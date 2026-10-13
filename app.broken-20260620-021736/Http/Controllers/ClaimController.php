<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Invoice;
use App\Support\CustomerPortalScope;
use App\Support\UploadedImageCompressor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $invoice = $this->claimableInvoiceQuery()->findOrFail((int) $data['invoice_id']);
        $proofPath = UploadedImageCompressor::store($request->file('proof_file'), 'claims', 'public');

        $claim = Claim::query()->create([
            'invoice_id' => (int) $invoice->id,
            'user_id' => (int) auth()->id(),
            'claim_type' => (string) $data['claim_type'],
            'amount' => (float) $data['amount'],
            'notes' => trim((string) $data['notes']),
            'proof_file' => $proofPath,
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

        return $query;
    }

    private function syncInvoicePaidStatus(Invoice $invoice): void
    {
        $invoice->refresh();
        $totalPaid = (float) $invoice->payments()->where('status', 'approved')->sum('amount');

        if ($totalPaid >= (float) $invoice->grand_total && $totalPaid > 0) {
            $invoice->update(['status' => 'paid']);
        }
    }
}
