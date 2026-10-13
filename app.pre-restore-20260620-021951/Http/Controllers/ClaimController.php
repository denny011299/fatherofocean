<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\Marking;
use App\Support\CustomerPortalScope;
use App\Support\DeliveryTripMediaStorage;
use App\Support\UploadedImageCompressor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class ClaimController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canAccessClaimsPage(), 403);

        $user = auth()->user();
        $query = Claim::query()->with(['invoice.customer', 'submitter', 'reviewer']);
        $statsQuery = Claim::query();

        if ($user?->hasRole('customer')) {
            $query->where('user_id', (int) $user->id);
            $statsQuery->where('user_id', (int) $user->id);
        }
        $this->applySalesClaimVisibilityScope($query, $user);
        $this->applySalesClaimVisibilityScope($statsQuery, $user);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('invoice', static function (Builder $invoiceQuery) use ($search): void {
                        $invoiceQuery->where('invoice_no', 'like', "%{$search}%")
                            ->orWhereHas('customer', static function (Builder $customerQuery) use ($search): void {
                                $customerQuery->where('name', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if ($status = trim((string) $request->get('status', ''))) {
            $query->where('status', $status);
        }

        if ($type = trim((string) $request->get('type', ''))) {
            $query->where('claim_type', $type);
        }

        $claims = $query->latest()->paginate(20);
        $invoiceOptions = $this->claimableInvoiceOptions($request);

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('status', Claim::STATUS_PENDING)->count(),
            'approved' => (clone $statsQuery)->where('status', Claim::STATUS_APPROVED)->count(),
            'rejected' => (clone $statsQuery)->where('status', Claim::STATUS_REJECTED)->count(),
        ];

        return view('claims.index', [
            'claims' => $claims,
            'invoiceOptions' => $invoiceOptions,
            'stats' => $stats,
            'canCreateClaim' => auth()->user()?->can('create claim') ?? false,
            'canApproveClaim' => auth()->user()?->can('approve claim') ?? false,
            'canRejectClaim' => auth()->user()?->can('reject claim') ?? false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('create claim') ?? false, 403);

        $data = $request->validate([
            'invoice_id' => 'required|integer|exists:invoices,id',
            'claim_type' => 'required|string|in:loss,damage,mistake',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'required|string|max:2000',
            'proof_file' => 'required|file',
        ]);

        $invoice = $this->claimableInvoiceQuery()->findOrFail((int) $data['invoice_id']);
        [$proofPath, $proofType] = $this->storeClaimProof($request->file('proof_file'));

        $claim = Claim::query()->create([
            'invoice_id' => (int) $invoice->id,
            'user_id' => (int) auth()->id(),
            'claim_type' => (string) $data['claim_type'],
            'amount' => (float) $data['amount'],
            'notes' => trim((string) $data['notes']),
            'proof_file' => $proofPath,
            'proof_type' => $proofType,
            'status' => Claim::STATUS_PENDING,
        ]);

        ActivityLog::record('Claim submitted', $claim, null, [
            'invoice_id' => $invoice->id,
            'claim_type' => $claim->claim_type,
            'amount' => (float) $claim->amount,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.claim_created'),
        ]);
    }

    public function approve(Request $request, Claim $claim): JsonResponse
    {
        abort_unless(auth()->user()?->can('approve claim') ?? false, 403);
        $this->assertClaimVisibleToUser($claim);
        abort_unless($claim->status === Claim::STATUS_PENDING, 422, __('messages.claim_already_processed'));

        $data = $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($claim, $data): void {
            $claim->update([
                'status' => Claim::STATUS_APPROVED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => isset($data['review_notes']) && trim((string) $data['review_notes']) !== ''
                    ? trim((string) $data['review_notes'])
                    : null,
            ]);

            $invoice = $claim->invoice;
            if ($invoice !== null) {
                // Claim approval updates invoice payable amount only.
                // Sales commission base remains shipment-based via SalesRevenueCalculator::billableBase().
                $invoice->recalculate();
                $this->syncInvoicePaidStatus($invoice);
            }

            ActivityLog::record('Claim approved', $claim);
        });

        return response()->json([
            'success' => true,
            'message' => __('messages.claim_approved'),
        ]);
    }

    public function reject(Request $request, Claim $claim): JsonResponse
    {
        abort_unless(auth()->user()?->can('reject claim') ?? false, 403);
        $this->assertClaimVisibleToUser($claim);
        abort_unless($claim->status === Claim::STATUS_PENDING, 422, __('messages.claim_already_processed'));

        $data = $request->validate([
            'review_notes' => 'required|string|max:1000',
        ]);

        $claim->update([
            'status' => Claim::STATUS_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => trim((string) $data['review_notes']),
        ]);

        ActivityLog::record('Claim rejected', $claim, null, [
            'review_notes' => $claim->review_notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.claim_rejected'),
        ]);
    }

    public function destroy(Claim $claim): JsonResponse
    {
        abort_unless((int) auth()->id() === (int) $claim->user_id, 403);
        abort_unless($claim->status === Claim::STATUS_PENDING, 422, __('messages.claim_cannot_delete'));

        $proofPath = $claim->proof_file;
        $meta = [
            'invoice_id' => $claim->invoice_id,
            'claim_type' => $claim->claim_type,
            'amount' => (float) $claim->amount,
        ];

        $claim->delete();

        if (is_string($proofPath) && $proofPath !== '' && Storage::disk('public')->exists($proofPath)) {
            Storage::disk('public')->delete($proofPath);
        }

        ActivityLog::record('Claim deleted', null, $meta);

        return response()->json([
            'success' => true,
            'message' => __('messages.claim_deleted'),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeClaimProof(UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        $photoMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $videoMimes = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo', 'video/3gpp'];

        if (in_array($mime, $photoMimes, true)) {
            if ($file->getSize() > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'proof_file' => [__('claim.proof_photo_too_large')],
                ]);
            }

            return [
                UploadedImageCompressor::store($file, 'claims', 'public'),
                Claim::PROOF_TYPE_PHOTO,
            ];
        }

        if (in_array($mime, $videoMimes, true)) {
            if ($file->getSize() > DeliveryTripMediaStorage::MAX_VIDEO_KB * 1024) {
                throw ValidationException::withMessages([
                    'proof_file' => [__('claim.proof_video_too_large')],
                ]);
            }

            return [
                (string) $file->store('claims', 'public'),
                Claim::PROOF_TYPE_VIDEO,
            ];
        }

        throw ValidationException::withMessages([
            'proof_file' => [__('claim.proof_invalid_file')],
        ]);
    }

    private function canAccessClaimsPage(): bool
    {
        return (auth()->user()?->can('view claim') ?? false)
            || (auth()->user()?->can('create claim') ?? false)
            || (auth()->user()?->can('approve claim') ?? false)
            || (auth()->user()?->can('reject claim') ?? false);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function claimableInvoiceOptions(Request $request): \Illuminate\Support\Collection
    {
        if (! (auth()->user()?->can('create claim') ?? false)) {
            return collect();
        }

        return $this->claimableInvoiceQuery()
            ->with('customer')
            ->latest()
            ->limit(100)
            ->get()
            ->map(static function (Invoice $invoice): array {
                return [
                    'id' => $invoice->id,
                    'label' => $invoice->invoice_no.' - '.trim((string) ($invoice->customer?->name ?? '-')),
                    'amount' => (float) $invoice->grand_total,
                ];
            });
    }

    /**
     * @return Builder<Invoice>
     */
    private function claimableInvoiceQuery(): Builder
    {
        $query = Invoice::query();
        $user = auth()->user();

        if ($user?->hasRole('customer')) {
            CustomerPortalScope::applyInvoiceScope($query, $user);
        }
        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictInvoicesQuery($query, $user);
        }

        return $query;
    }

    /**
     * Sales/supervisor can only see claims they submitted or that belong to their team marking scope.
     *
     * @param  Builder<Claim>  $query
     */
    private function applySalesClaimVisibilityScope(Builder $query, ?\App\Models\User $user): void
    {
        if (! Marking::userHasTeamMarkingScope($user)) {
            return;
        }

        $query->where(function (Builder $scope) use ($user): void {
            $scope->where('user_id', (int) $user->id)
                ->orWhereHas('invoice', function (Builder $invoiceQuery) use ($user): void {
                    Marking::restrictInvoicesQuery($invoiceQuery, $user);
                });
        });
    }

    private function assertClaimVisibleToUser(Claim $claim): void
    {
        $user = auth()->user();
        if (! Marking::userHasTeamMarkingScope($user)) {
            return;
        }

        $visible = Claim::query()->whereKey($claim->id);
        $this->applySalesClaimVisibilityScope($visible, $user);
        abort_unless($visible->exists(), 403);
    }

    private function syncInvoicePaidStatus(Invoice $invoice): void
    {
        $invoice->refresh();
        $totalPaid = (float) $invoice->payments()->where('status', 'approved')->sum('amount');

        if ((float) $invoice->grand_total <= 0.01 || $totalPaid >= (float) $invoice->grand_total) {
            $invoice->update(['status' => 'paid']);
        }
    }
}
