<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesRevenueExport;
use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\User;
use App\Support\CompanyRevenueSettings;
use App\Support\SalesCommissionSettings;
use App\Support\SalesIncentiveService;
use App\Support\SalesRevenueCalculator;
use App\Support\SalesSalaryAggregator;
use App\Support\ShipmentEarningsSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SalesRevenueController extends Controller
{
    public function salesIndex(): View
    {
        abort_unless($this->canView(), 403);

        return view('finance.sales-earnings', $this->sharedViewData());
    }

    public function companyIndex(): View
    {
        $this->assertCanViewCompanyRevenue();

        $salesIds = Marking::query()
            ->whereNotNull('sales_id')
            ->distinct()
            ->pluck('sales_id');

        $marketingOptions = User::query()
            ->whereIn('id', $salesIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('finance.company-revenue', array_merge($this->sharedViewData(), [
            'marketingOptions' => $marketingOptions,
        ]));
    }

    public function companyMarkingOptions(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $request->validate(['sales_id' => 'required|integer|exists:users,id']);

        $markings = Marking::query()
            ->active()
            ->where('sales_id', (int) $request->input('sales_id'))
            ->orderBy('code')
            ->get(['code', 'customer_name'])
            ->map(static fn (Marking $marking): array => [
                'code' => $marking->code,
                'label' => trim($marking->code.($marking->customer_name ? ' — '.$marking->customer_name : '')),
            ])
            ->values();

        return response()->json($markings);
    }

    public function companySummary(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $period = (string) $request->input('period', now()->format('Y-m'));
        SalesSalaryAggregator::assertValidPeriod($period);
        $range = SalesSalaryAggregator::periodDateRange($period);

        $filters = $this->companyFiltersFromRequest($request);

        $totals = SalesRevenueCalculator::totalsForUser(
            $this->scopedUser(auth()->user()),
            $range['date_from'],
            $range['date_to'],
            $filters['marketing_id'],
            $filters['excluded_markings'],
        );

        return response()->json([
            'estimasi_company' => $totals['estimasi_company'],
            'fix_company' => $totals['fix_company'],
        ]);
    }

    /** @return array<string, mixed> */
    private function sharedViewData(): array
    {
        $user = auth()->user();
        $isScopedSales = $user?->hasAnyRole(['sales', 'supervisor']) ?? false;

        return [
            'totals' => SalesRevenueCalculator::totalsForUser($this->scopedUser($user)),
            'isSales' => $isScopedSales,
            'isSupervisor' => $user?->hasRole('supervisor') ?? false,
            'isSupervisorOnly' => $user?->isSupervisorOnly() ?? false,
            'canUploadSalaryProof' => $user?->can('upload sales salary proof') ?? false,
            'commissionRates' => SalesCommissionSettings::current(),
            'companyRevenueFixed' => CompanyRevenueSettings::fixedAmount(),
        ];
    }

    public function companyData(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $filters = $this->companyFiltersFromRequest($request);

        return response()->json(SalesRevenueCalculator::companyRevenueRows(
            $this->scopedUser(auth()->user()),
            $filters['status'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['marketing_id'],
            $filters['excluded_markings'],
        ));
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $filters = $this->filtersFromRequest($request);

        return response()->json(SalesRevenueCalculator::totalsForUser(
            $this->scopedUser(auth()->user()),
            $filters['date_from'],
            $filters['date_to'],
        ));
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $filters = $this->filtersFromRequest($request);

        $rows = SalesRevenueCalculator::commissionRows(
            $this->scopedUser(auth()->user()),
            $filters['status'],
            $filters['date_from'],
            $filters['date_to'],
        );

        return response()->json(SalesRevenueCalculator::stripCompanySecretsFromRows($rows));
    }

    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($this->canView(), 403);

        $user = auth()->user();
        $filters = $this->filtersFromRequest($request);
        $isScopedSales = $user?->hasAnyRole(['sales', 'supervisor']) ?? false;

        $rows = SalesRevenueCalculator::stripCompanySecretsFromRows(
            SalesRevenueCalculator::commissionRows(
                $this->scopedUser($user),
                $filters['status'],
                $filters['date_from'],
                $filters['date_to'],
            ),
        );

        $filename = 'penghasilan-sales-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new SalesRevenueExport($rows, ! $isScopedSales),
            $filename,
        );
    }

    public function badgeCount(): JsonResponse
    {
        if (! auth()->user()?->can('confirm sales commission')) {
            return response()->json(['waiting' => 0]);
        }

        return response()->json([
            'waiting' => SalesRevenueCalculator::adminWaitingConfirmCount(),
        ]);
    }

    public function confirm(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(auth()->user()?->can('confirm sales commission'), 403);

        $record = ShipmentEarningsSettlement::syncForBarang($barang);
        if ($record === null) {
            return response()->json(['message' => 'Pengiriman tidak memiliki sales pada marking.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('finance-earnings.auto_settle_hint'),
            'commission' => [
                'id' => $record->id,
                'status' => $record->status,
                'confirmed_amount' => (float) ($record->confirmed_amount ?? $record->estimated_amount),
            ],
        ]);
    }

    public function markCair(SalesCommission $salesCommission): JsonResponse
    {
        abort_unless(auth()->user()?->can('mark sales commission cair'), 403);

        if ($salesCommission->status === SalesCommission::STATUS_CAIR) {
            return response()->json(['message' => 'Sudah cair.'], 422);
        }

        if ($salesCommission->status !== SalesCommission::STATUS_CONFIRMED) {
            return response()->json(['message' => 'Konfirmasi nominal terlebih dahulu.'], 422);
        }

        $salesCommission->update([
            'status' => SalesCommission::STATUS_CAIR,
            'paid_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function canView(): bool
    {
        return auth()->user()?->can('view sales revenue') ?? false;
    }

    private function assertCanViewCompanyRevenue(): void
    {
        abort_unless($this->canView(), 403);
        abort_if(auth()->user()?->hasAnyRole(['sales', 'supervisor']), 403);
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

    /** @return array{status: string, date_from: ?string, date_to: ?string, marketing_id: ?int, excluded_markings: list<string>} */
    private function companyFiltersFromRequest(Request $request): array
    {
        $base = $this->filtersFromRequest($request);

        $excluded = $request->input('excluded_markings', []);
        if (is_string($excluded)) {
            $excluded = $excluded !== '' ? explode(',', $excluded) : [];
        }
        if (! is_array($excluded)) {
            $excluded = [];
        }

        $base['marketing_id'] = $request->filled('marketing_id')
            ? (int) $request->input('marketing_id')
            : null;
        $base['excluded_markings'] = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $excluded,
        )));

        return $base;
    }

    /** @return array{status: string, date_from: ?string, date_to: ?string} */
    private function filtersFromRequest(Request $request): array
    {
        $rules = [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ];
        if ($request->filled('status')) {
            $rules['status'] = 'string|in:waiting,fixed,confirmed,cair';
        }

        $validated = $request->validate($rules);

        return [
            'status' => (string) ($validated['status'] ?? $request->input('status', '')),
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

}
