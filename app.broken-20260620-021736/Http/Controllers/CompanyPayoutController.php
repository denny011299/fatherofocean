<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyPayout;
use App\Models\User;
use App\Support\SalesRevenueCalculator;
use App\Support\SalesSalaryAggregator;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyPayoutController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);
        abort_if(auth()->user()?->hasAnyRole(['sales', 'supervisor']), 403);

        $period = (string) $request->input('period', '');

        $query = CompanyPayout::query()
            ->with('creator:id,name')
            ->orderByDesc('payout_date')
            ->orderByDesc('id');

        if ($period !== '') {
            SalesSalaryAggregator::assertValidPeriod($period);
            $range = SalesSalaryAggregator::periodDateRange($period);
            $query->whereBetween('payout_date', [$range['date_from'], $range['date_to']]);
        }

        $rows = $query->get()->map(static function (CompanyPayout $payout): array {
            return [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'payout_date' => $payout->payout_date?->format('d/m/Y'),
                'payout_date_raw' => $payout->payout_date?->format('Y-m-d'),
                'note' => $payout->note,
                'proof_url' => $payout->proofUrl(),
                'created_by_name' => $payout->creator?->name,
                'created_at' => $payout->created_at?->format('d/m/Y H:i'),
            ];
        });

        return response()->json($rows->values());
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);
        abort_if(auth()->user()?->hasAnyRole(['sales', 'supervisor']), 403);

        $period = (string) $request->input('period', now()->format('Y-m'));
        SalesSalaryAggregator::assertValidPeriod($period);
        $range = SalesSalaryAggregator::periodDateRange($period);

        $totals = SalesRevenueCalculator::totalsForUser(
            $this->scopedUser(auth()->user()),
            $range['date_from'],
            $range['date_to'],
        );

        $fixCompany = (float) $totals['fix_company'];
        $totalWithdrawn = round((float) CompanyPayout::query()
            ->whereBetween('payout_date', [$range['date_from'], $range['date_to']])
            ->sum('amount'), 0);
        $remaining = max(0.0, $fixCompany - $totalWithdrawn);

        return response()->json([
            'fix_company' => $fixCompany,
            'total_withdrawn' => $totalWithdrawn,
            'remaining_balance' => $remaining,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canManage(), 403);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payout_date' => 'required|date',
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:5120',
            'note' => 'nullable|string|max:500',
        ]);

        $path = UploadedImageCompressor::store($request->file('proof_file'), 'company-payout-proofs', 'public');

        $payout = CompanyPayout::query()->create([
            'amount' => round((float) $validated['amount'], 2),
            'payout_date' => $validated['payout_date'],
            'proof_file' => $path,
            'note' => $validated['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => __('company-payout.saved'),
            'payout' => [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'payout_date' => $payout->payout_date?->format('d/m/Y'),
                'proof_url' => $payout->proofUrl(),
            ],
        ]);
    }

    private function canView(): bool
    {
        return auth()->user()?->can('view sales revenue') ?? false;
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('manage company payout') ?? false;
    }

    private function scopedUser(?User $user): ?User
    {
        if ($user?->hasRole('sales')) {
            return $user;
        }

        if ($user?->hasRole('supervisor')) {
            return $user;
        }

        return null;
    }
}
