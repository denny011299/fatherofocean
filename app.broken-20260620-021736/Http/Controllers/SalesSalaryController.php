<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SalesSalaryPayout;
use App\Models\User;
use App\Support\SalesSalaryAggregator;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class SalesSalaryController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $scopedId = SalesSalaryAggregator::resolveScopedSalesId();
        $salesFilter = $request->integer('sales_id');

        if ($scopedId !== null) {
            $salesFilter = $scopedId;
        }

        $period = (string) $request->input('period', now()->format('Y-m'));
        SalesSalaryAggregator::assertValidPeriod($period);

        $rows = SalesSalaryAggregator::monthlyPayoutMatrix(
            $period,
            $salesFilter > 0 ? $salesFilter : null,
        );

        return response()->json($rows->values());
    }

    public function salesOptions(): JsonResponse
    {
        abort_unless($this->canView() && $this->canManageAll(), 403);

        $ids = \App\Models\SalesCommission::query()
            ->whereIn('status', SalesSalaryAggregator::settledStatuses())
            ->distinct()
            ->pluck('sales_id');

        $users = User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $u) => ['id' => $u->id, 'text' => $u->name]);

        return response()->json($users);
    }

    public function detail(int $salesId, string $period): JsonResponse
    {
        abort_unless($this->canView(), 403);
        $this->assertSalesAccess($salesId);

        return response()->json(SalesSalaryAggregator::periodDetail($salesId, $period));
    }

    public function uploadProof(Request $request, int $salesId, string $period): JsonResponse
    {
        abort_unless($this->canUpload(), 403);
        $this->assertSalesAccess($salesId, allowManageAll: true);

        SalesSalaryAggregator::assertValidPeriod($period);

        if (! SalesSalaryAggregator::periodHasCommissions($salesId, $period)) {
            return response()->json(['message' => __('sales-salary.no_commissions_period')], 422);
        }

        $validated = $request->validate([
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:5120',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $payout = SalesSalaryPayout::query()->firstOrNew([
            'sales_id' => $salesId,
            'period' => $period,
        ]);

        if ($payout->proof_file) {
            Storage::disk('public')->delete($payout->proof_file);
        }

        $path = UploadedImageCompressor::store($request->file('proof_file'), 'salary-proofs', 'public');

        $payout->fill([
            'proof_file' => $path,
            'proof_uploaded_by' => auth()->id(),
            'proof_uploaded_at' => now(),
            'admin_note' => $validated['admin_note'] ?? $payout->admin_note,
        ]);
        $payout->save();

        return response()->json([
            'success' => true,
            'message' => __('sales-salary.proof_uploaded'),
            'proof_url' => $payout->proofUrl(),
            'proof_uploaded_at' => $payout->proof_uploaded_at?->format('d/m/Y H:i'),
        ]);
    }

    private function canView(): bool
    {
        return auth()->user()?->can('view sales revenue') ?? false;
    }

    private function canUpload(): bool
    {
        return auth()->user()?->can('upload sales salary proof') ?? false;
    }

    private function canManageAll(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ! $user->hasAnyRole(['sales', 'supervisor']);
    }

    private function assertSalesAccess(int $salesId, bool $allowManageAll = false): void
    {
        $scopedId = SalesSalaryAggregator::resolveScopedSalesId();

        if ($scopedId !== null && $scopedId !== $salesId) {
            abort(403);
        }

        if ($scopedId === null && ! $allowManageAll && ! $this->canManageAll()) {
            abort(403);
        }
    }
}
