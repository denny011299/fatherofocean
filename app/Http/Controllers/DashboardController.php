<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\BarangTask;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\User;
use App\Support\CbmFormat;
use App\Support\CompanyRevenueSettings;
use App\Support\CustomerPortalScope;
use App\Support\DashboardTeamMetrics;
use App\Support\DashboardWidgetPermissions;
use App\Support\SalesCommissionSettings;
use App\Support\SalesRevenueCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();

        if (Marking::userCanViewAll($user)) {
            return $this->staffDashboard($request, isSales: false);
        }

        if (Marking::userHasTeamMarkingScope($user)) {
            return $this->staffDashboard(
                $request,
                scopedUser: $user,
                isSales: $user->hasAnyRole(['sales', 'supervisor']),
            );
        }

        if ($user->hasRole('customer')) {
            return $this->customerDashboard();
        }

        if ($user->hasRole('finance')) {
            return $this->financeDashboard();
        }

        if ($user->hasRole('admin_gudang_china')) {
            return $this->gudangChinaDashboard();
        }

        if ($user->hasRole('admin_gudang_indo')) {
            return $this->gudangIndoDashboard();
        }

        return $this->staffDashboard($request, isSales: false);
    }

    public function shipmentChartData(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can(DashboardWidgetPermissions::SHIPMENT_CHART), 403);

        $user = auth()->user();
        $scopedUser = Marking::userHasTeamMarkingScope($user) ? $user : null;

        $year = max(1999, min(now()->year, (int) $request->query('year', now()->year)));
        $months = $this->getMonthlyShipmentsForYear($year, $scopedUser);

        return response()->json([
            'year' => $year,
            'labels' => $months->keys()->map(
                fn (string $m) => Carbon::createFromFormat('Y-m', $m)->translatedFormat('M Y')
            )->values(),
            'data' => $months->values(),
        ]);
    }

    public function companyValueData(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can(DashboardWidgetPermissions::COMPANY_VALUE), 403);

        [$dateFrom, $dateTo, $year, $month] = $this->resolveCompanyValuePeriod($request);
        $salesId = $this->resolveDashboardSalesId($request);
        $totals = SalesRevenueCalculator::totalsForUser(null, $dateFrom, $dateTo, $salesId);
        $totalValue = (float) ($totals['estimasi_company'] + $totals['fix_company']);

        [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($year, $month);
        $lastMonthTotals = SalesRevenueCalculator::totalsForUser(null, $lastFrom, $lastTo, $salesId);
        $lastMonthValue = (float) ($lastMonthTotals['estimasi_company'] + $lastMonthTotals['fix_company']);
        $lastMonthFormatted = number_format($lastMonthValue, 0, ',', '.');

        return response()->json([
            'year' => $year,
            'month' => $month,
            'sales_id' => $salesId,
            'total_value' => $totalValue,
            'total_value_formatted' => number_format($totalValue, 0, ',', '.'),
            'last_month_value' => $lastMonthValue,
            'last_month_value_formatted' => $lastMonthFormatted,
            'last_month_label' => $lastMonthRef->translatedFormat('M Y'),
            'last_month_text' => __('dashboard.company_value_last_month', [
                'month' => $lastMonthRef->translatedFormat('M Y'),
                'amount' => $lastMonthFormatted,
            ]),
        ]);
    }

    public function adminCbmData(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);
        abort_if($user->hasAnyRole(['sales', 'supervisor']), 403);

        $canView = $user->can(DashboardWidgetPermissions::TOTAL_CBM)
            || ($user->hasRole('super_admin') && $user->can(DashboardWidgetPermissions::COMPANY_VALUE));
        abort_unless($canView, 403);

        [$dateFrom, $dateTo, $year, $month] = $this->resolveCompanyValuePeriod($request);
        $salesId = $this->resolveDashboardSalesId($request);

        $cbm = DashboardTeamMetrics::totalCbm(null, $dateFrom, $dateTo, $salesId);
        [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($year, $month);
        $cbmLast = DashboardTeamMetrics::totalCbm(null, $lastFrom, $lastTo, $salesId);
        $lastMonthLabel = $lastMonthRef->translatedFormat('M Y');

        return response()->json([
            'year' => $year,
            'month' => $month,
            'sales_id' => $salesId,
            'cbm' => $cbm,
            'cbm_formatted' => CbmFormat::format($cbm),
            'cbm_last_month' => $cbmLast,
            'cbm_last_month_formatted' => CbmFormat::format($cbmLast),
            'last_month_label' => $lastMonthLabel,
            'cbm_last_month_text' => __('dashboard.team_cbm_last_month', [
                'month' => $lastMonthLabel,
                'amount' => CbmFormat::format($cbmLast),
            ]),
        ]);
    }

    public function teamMetricsData(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user !== null && Marking::userHasTeamMarkingScope($user), 403);

        [$dateFrom, $dateTo, $year, $month] = $this->resolveCompanyValuePeriod($request);
        [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($year, $month);
        $lastMonthLabel = $lastMonthRef->translatedFormat('M Y');

        $payload = [
            'year' => $year,
            'month' => $month,
            'last_month_label' => $lastMonthLabel,
        ];

        if ($user->can(DashboardWidgetPermissions::OMSET)) {
            $commission = DashboardTeamMetrics::commissionTotal($user, $dateFrom, $dateTo);
            $commissionLast = DashboardTeamMetrics::commissionTotal($user, $lastFrom, $lastTo);
            $payload['commission'] = $commission;
            $payload['commission_formatted'] = number_format($commission, 0, ',', '.');
            $payload['commission_last_month'] = $commissionLast;
            $payload['commission_last_month_formatted'] = number_format($commissionLast, 0, ',', '.');
            $payload['commission_last_month_text'] = __('dashboard.team_commission_last_month', [
                'month' => $lastMonthLabel,
                'amount' => number_format($commissionLast, 0, ',', '.'),
            ]);
        }

        if ($user->can(DashboardWidgetPermissions::TOTAL_CBM)) {
            $cbm = DashboardTeamMetrics::totalCbm($user, $dateFrom, $dateTo);
            $cbmLast = DashboardTeamMetrics::totalCbm($user, $lastFrom, $lastTo);
            $payload['cbm'] = $cbm;
            $payload['cbm_formatted'] = CbmFormat::format($cbm);
            $payload['cbm_last_month'] = $cbmLast;
            $payload['cbm_last_month_formatted'] = CbmFormat::format($cbmLast);
            $payload['cbm_last_month_text'] = __('dashboard.team_cbm_last_month', [
                'month' => $lastMonthLabel,
                'amount' => CbmFormat::format($cbmLast),
            ]);
        }

        return response()->json($payload);
    }

    public function teamOmsetTotalData(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user !== null && Marking::userHasTeamMarkingScope($user), 403);
        abort_unless($user->can(DashboardWidgetPermissions::SALES_REVENUE), 403);

        [$dateFrom, $dateTo, $year, $month] = $this->resolveCompanyValuePeriod($request);
        [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($year, $month);
        $lastMonthLabel = $lastMonthRef->translatedFormat('M Y');

        $omset = DashboardTeamMetrics::paidOmset($user, $dateFrom, $dateTo);
        $omsetLast = DashboardTeamMetrics::paidOmset($user, $lastFrom, $lastTo);
        $omsetFormatted = number_format($omset, 0, ',', '.');
        $omsetLastFormatted = number_format($omsetLast, 0, ',', '.');

        return response()->json([
            'year' => $year,
            'month' => $month,
            'omset' => $omset,
            'omset_formatted' => $omsetFormatted,
            'omset_last_month' => $omsetLast,
            'omset_last_month_formatted' => $omsetLastFormatted,
            'last_month_label' => $lastMonthLabel,
            'omset_last_month_text' => __('dashboard.total_my_omset_last_month', [
                'month' => $lastMonthLabel,
                'amount' => $omsetLastFormatted,
            ]),
        ]);
    }

    /** Admin dashboard (global or per-marking for sales). */
    private function staffDashboard(Request $request, ?User $scopedUser = null, bool $isSales = false): View
    {
        $user = auth()->user();
        $widgets = DashboardWidgetPermissions::visibilityFor($user);
        $scoped = $scopedUser !== null;

        $onTheWayStatuses = [Barang::STATUS_ETD, Barang::STATUS_ETA];

        $totalKirimanBerjalan = 0;
        if ($widgets['active_shipments']) {
            $kirimanQuery = Barang::whereIn('status', $onTheWayStatuses);
            if ($scoped) {
                Marking::restrictBarangsQuery($kirimanQuery, $scopedUser);
            }
            $totalKirimanBerjalan = $kirimanQuery->count();
        }

        $totalMasterMarking = 0;
        if ($widgets['master_marking']) {
            $totalMasterMarking = $scoped
                ? Marking::visibleToUser($user)->count()
                : Marking::count();
        }

        $totalTagihan = 0;
        if ($widgets['invoices']) {
            $tagihanQuery = Invoice::whereIn('status', ['draft', 'sent']);
            if ($scoped) {
                $tagihanQuery->whereHas(
                    'items',
                    fn ($q) => $q->whereHas(
                        'barang',
                        fn ($bq) => Marking::restrictBarangsQuery($bq, $scopedUser),
                    ),
                );
            }
            $totalTagihan = $tagihanQuery->count();
        }

        $todayBarangs = collect();
        if ($widgets['daily_task']) {
            $todayBarangQuery = Barang::with('customer', 'tasks.assignee', 'products')->whereDate('tanggal_masuk', today());
            if ($scoped) {
                Marking::restrictBarangsQuery($todayBarangQuery, $scopedUser);
            }
            $todayBarangs = $todayBarangQuery->latest()->get();
        }

        $taskBarangOptions = collect();
        if ($widgets['daily_task']) {
            $taskBarangQuery = Barang::query()
                ->select('id', 'marking', 'no_index', 'ctns', 'tanggal_masuk', 'status')
                ->whereIn('status', [
                    Barang::STATUS_STUFFING,
                    Barang::STATUS_INBOUND,
                    Barang::STATUS_ETD,
                    Barang::STATUS_ETA,
                ])
                ->orderByDesc('tanggal_masuk')
                ->orderByDesc('id')
                ->limit(200);
            if ($scoped) {
                Marking::restrictBarangsQuery($taskBarangQuery, $scopedUser);
            }
            $taskBarangOptions = $taskBarangQuery->get();
        }

        $kritikSaran = collect();
        if ($widgets['feedback']) {
            $kritikQuery = Rating::with('user', 'barang', 'assignee')
                ->whereNotNull('review')
                ->where('review', '!=', '');
            if ($scoped) {
                $kritikQuery->where('assigned_to', $user->id);
            }
            $kritikSaran = $kritikQuery->latest()->take(10)->get();
        }

        $shipmentChartYear = max(1999, min(now()->year, (int) $request->query('chart_year', now()->year)));
        $monthlyShipments = collect();
        $shipmentYears = [now()->year];
        if ($widgets['shipment_chart']) {
            $monthlyShipments = $this->getMonthlyShipmentsForYear($shipmentChartYear, $scopedUser);
            $shipmentYears = $this->getShipmentYears($scopedUser);
        }

        $needsStaffList = ($widgets['feedback'] && $user->can('assign feedback'))
            || ($widgets['daily_task'] && $user->can('manage task'));
        $staffList = ($isSales || ! $needsStaffList)
            ? collect()
            : User::role(['admin_cs', 'sales', 'admin_gudang_china', 'admin_gudang_indo', 'finance'])
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

        $teamOmsetTotal = null;
        if ($isSales && $widgets['sales_revenue']) {
            [$omsetDateFrom, $omsetDateTo, $omsetFilterYear, $omsetFilterMonth] = $this->resolveCompanyValuePeriod($request);
            [$omsetLastFrom, $omsetLastTo, $omsetLastMonthRef] = $this->resolveCompanyValueLastMonthPeriod(
                $omsetFilterYear,
                $omsetFilterMonth,
            );
            $teamOmsetTotal = [
                'year' => $omsetFilterYear,
                'month' => $omsetFilterMonth,
                'omset' => DashboardTeamMetrics::paidOmset($scopedUser, $omsetDateFrom, $omsetDateTo),
                'omset_last_month' => DashboardTeamMetrics::paidOmset($scopedUser, $omsetLastFrom, $omsetLastTo),
                'last_month_label' => $omsetLastMonthRef->translatedFormat('M Y'),
            ];
        }

        $isSuperAdmin = $user->hasRole('super_admin');
        [$companyDateFrom, $companyDateTo, $companyFilterYear, $companyFilterMonth] = $this->resolveCompanyValuePeriod($request);
        $companyFilterSalesId = ($isSuperAdmin && $widgets['company_value'])
            ? $this->resolveDashboardSalesId($request)
            : null;
        $companyRevenue = ($isSuperAdmin && $widgets['company_value'])
            ? SalesRevenueCalculator::totalsForUser(null, $companyDateFrom, $companyDateTo, $companyFilterSalesId)
            : null;

        $companyRevenueLastMonth = null;
        $companyRevenueLastMonthLabel = null;
        if ($isSuperAdmin && $widgets['company_value']) {
            [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod(
                $companyFilterYear,
                $companyFilterMonth,
            );
            $lastMonthTotals = SalesRevenueCalculator::totalsForUser(null, $lastFrom, $lastTo, $companyFilterSalesId);
            $companyRevenueLastMonth = (float) ($lastMonthTotals['estimasi_company'] + $lastMonthTotals['fix_company']);
            $companyRevenueLastMonthLabel = $lastMonthRef->translatedFormat('M Y');
        }

        $dashboardSalesOptions = collect();
        if (
            ($isSuperAdmin && $widgets['company_value'])
            || (! $isSales && ($widgets['total_cbm'] || $widgets['company_value']))
        ) {
            $dashboardSalesOptions = $this->dashboardSalesOptions();
        }

        $adminCbmMetrics = null;
        $canAdminCbm = ! $isSales && ($widgets['total_cbm'] || ($isSuperAdmin && $widgets['company_value']));
        if ($canAdminCbm) {
            $adminSalesId = $this->resolveDashboardSalesId($request);
            [$cbmFrom, $cbmTo, $cbmYear, $cbmMonth] = $this->resolveCompanyValuePeriod($request);
            [$cbmLastFrom, $cbmLastTo, $cbmLastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($cbmYear, $cbmMonth);
            $adminCbmMetrics = [
                'year' => $cbmYear,
                'month' => $cbmMonth,
                'sales_id' => $adminSalesId,
                'cbm' => DashboardTeamMetrics::totalCbm(null, $cbmFrom, $cbmTo, $adminSalesId),
                'cbm_last_month' => DashboardTeamMetrics::totalCbm(null, $cbmLastFrom, $cbmLastTo, $adminSalesId),
                'last_month_label' => $cbmLastMonthRef->translatedFormat('M Y'),
            ];
        }

        $totalOmset = ($widgets['omset'] && ! $isSales)
            ? DashboardTeamMetrics::paidOmset($scopedUser, null, null)
            : 0;

        $teamMetrics = null;
        $isSupervisor = $user->hasRole('supervisor');
        if ($isSales && ($widgets['omset'] || $widgets['total_cbm'])) {
            [$teamDateFrom, $teamDateTo, $teamFilterYear, $teamFilterMonth] = $this->resolveCompanyValuePeriod($request);
            [$teamLastFrom, $teamLastTo, $teamLastMonthRef] = $this->resolveCompanyValueLastMonthPeriod(
                $teamFilterYear,
                $teamFilterMonth,
            );
            $teamMetrics = [
                'year' => $teamFilterYear,
                'month' => $teamFilterMonth,
                'last_month_label' => $teamLastMonthRef->translatedFormat('M Y'),
            ];
            if ($widgets['omset']) {
                $teamMetrics['commission'] = DashboardTeamMetrics::commissionTotal($scopedUser, $teamDateFrom, $teamDateTo);
                $teamMetrics['commission_last_month'] = DashboardTeamMetrics::commissionTotal($scopedUser, $teamLastFrom, $teamLastTo);
            }
            if ($widgets['total_cbm']) {
                $teamMetrics['cbm'] = DashboardTeamMetrics::totalCbm($scopedUser, $teamDateFrom, $teamDateTo);
                $teamMetrics['cbm_last_month'] = DashboardTeamMetrics::totalCbm($scopedUser, $teamLastFrom, $teamLastTo);
            }
        }

        return view('dashboard.admin', [
            'widgets' => $widgets,
            'totalKirimanBerjalan' => $totalKirimanBerjalan,
            'totalMasterMarking' => $totalMasterMarking,
            'totalTagihan' => $totalTagihan,
            'totalOmset' => $totalOmset,
            'teamOmsetTotal' => $teamOmsetTotal,
            'companyRevenue' => $companyRevenue,
            'companyRevenueLastMonth' => $companyRevenueLastMonth,
            'companyRevenueLastMonthLabel' => $companyRevenueLastMonthLabel,
            'companyRevenueFixed' => ($isSuperAdmin && $widgets['company_value']) ? CompanyRevenueSettings::fixedAmount() : null,
            'companyFilterYear' => $companyFilterYear,
            'companyFilterMonth' => $companyFilterMonth,
            'companyFilterSalesId' => $companyFilterSalesId,
            'dashboardSalesOptions' => $dashboardSalesOptions,
            'adminCbmMetrics' => $adminCbmMetrics,
            'monthlyShipments' => $monthlyShipments,
            'shipmentChartYear' => $shipmentChartYear,
            'shipmentYears' => $shipmentYears,
            'kritikSaran' => $kritikSaran,
            'todayBarangs' => $todayBarangs,
            'taskBarangOptions' => $taskBarangOptions,
            'staffList' => $staffList,
            'isSales' => $isSales,
            'isSupervisor' => $isSupervisor,
            'teamMetrics' => $teamMetrics,
            'salesCommissionRate' => ($isSales && $scopedUser !== null)
                ? SalesCommissionSettings::rateForSalesUser($scopedUser)
                : null,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function assignFeedback(Request $request, Rating $rating): JsonResponse
    {
        abort_unless(auth()->user()?->can('assign feedback'), 403);

        $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $rating->update([
            'assigned_to' => $request->assigned_to,
            'admin_notes' => $request->admin_notes,
        ]);

        $rating->load('assignee');

        return response()->json([
            'success' => true,
            'assignee_name' => $rating->assignee?->name,
        ]);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?int}
     */
    private function resolveCompanyValuePeriod(?Request $request): array
    {
        $year = $request?->filled('company_year') ? (int) $request->query('company_year') : null;
        $month = $request?->filled('company_month') ? (int) $request->query('company_month') : null;

        if ($year === null) {
            $year = (int) now()->format('Y');
            $month = (int) now()->format('n');
            $from = Carbon::create($year, $month, 1)->startOfMonth();
            $to = $from->copy()->endOfMonth();

            return [$from->format('Y-m-d'), $to->format('Y-m-d'), $year, $month];
        }

        $year = max(1999, min(now()->year, $year));

        if ($month !== null && $month >= 1 && $month <= 12) {
            $from = Carbon::create($year, $month, 1)->startOfMonth();
            $to = $from->copy()->endOfMonth();

            return [$from->format('Y-m-d'), $to->format('Y-m-d'), $year, $month];
        }

        return [
            Carbon::create($year, 1, 1)->format('Y-m-d'),
            Carbon::create($year, 12, 31)->format('Y-m-d'),
            $year,
            null,
        ];
    }

    private function resolveDashboardSalesId(?Request $request = null): ?int
    {
        $request ??= request();
        if (! $request->filled('sales_id')) {
            return null;
        }

        $salesId = (int) $request->query('sales_id');

        return $salesId > 0 ? $salesId : null;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function dashboardSalesOptions(): \Illuminate\Support\Collection
    {
        $salesIds = Marking::query()
            ->whereNotNull('sales_id')
            ->distinct()
            ->pluck('sales_id');

        return User::query()
            ->whereIn('id', $salesIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Previous calendar month relative to the selected company-value filter.
     *
     * @return array{0: string, 1: string, 2: Carbon}
     */
    private function resolveCompanyValueLastMonthPeriod(?int $year, ?int $month): array
    {
        if ($year !== null && $month !== null && $month >= 1 && $month <= 12) {
            $ref = Carbon::create($year, $month, 1)->subMonth();
        } elseif ($year !== null) {
            $ref = Carbon::create($year, 12, 1)->subMonth();
        } else {
            $ref = now()->subMonth();
        }

        return [
            $ref->copy()->startOfMonth()->format('Y-m-d'),
            $ref->copy()->endOfMonth()->format('Y-m-d'),
            $ref,
        ];
    }

    /** @return list<int> */
    private function getShipmentYears(?User $scopedUser = null): array
    {
        unset($scopedUser);

        $years = [];
        for ($y = now()->year; $y >= 1999; $y--) {
            $years[] = $y;
        }

        return $years;
    }

    private function getMonthlyShipmentsForYear(int $year, ?User $scopedUser = null): \Illuminate\Support\Collection
    {
        $query = Barang::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(*) as total')
        )
            ->whereYear('created_at', $year);

        if ($scopedUser !== null) {
            Marking::restrictBarangsQuery($query, $scopedUser);
        }

        $monthlyShipments = $query->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $months = collect();
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%04d-%02d', $year, $m);
            $months[$key] = $monthlyShipments[$key] ?? 0;
        }

        return $months;
    }

    private function customerDashboard(): View
    {
        $user = auth()->user();

        $barangQuery = Barang::query();
        CustomerPortalScope::applyBarangScope($barangQuery, $user);

        Barang::backfillMissingInvoicesForBongkar(clone $barangQuery, 100);

        $invoiceQuery = Invoice::query();
        CustomerPortalScope::applyInvoiceScope($invoiceQuery, $user);

        return view('dashboard.customer', array_merge(
            CustomerPortalScope::portalMarkingViewData($user),
            [
            'myBarangs' => (clone $barangQuery)
                ->with('latestTracking', 'rating', 'containers', 'invoiceItems.invoice.payments')
                ->latest()
                ->take(10)
                ->get(),
            'myInvoices' => (clone $invoiceQuery)->with(['payments', 'items.barang'])->latest()->take(5)->get(),
            'totalBarang' => (clone $barangQuery)->count(),
            'unpaidInvoices' => (clone $invoiceQuery)->whereIn('status', ['draft', 'sent'])->count(),
            'deliveredCount' => (clone $barangQuery)->where('status', Barang::STATUS_BONGKAR)->count(),
            ],
        ));
    }

    private function financeDashboard(): View
    {
        return view('dashboard.finance', [
            'pendingPayments' => Payment::where('status', 'pending')->count(),
            'totalInvoices' => Invoice::count(),
            'unpaidTotal' => Invoice::whereIn('status', ['draft', 'sent'])->sum('grand_total'),
            'paidTotal' => Invoice::where('status', 'paid')->sum('grand_total'),
            'recentPayments' => Payment::with('invoice', 'customer')->latest()->take(10)->get(),
        ]);
    }

    private function gudangChinaDashboard(): View
    {
        return view('dashboard.gudang-china', [
            'todayInbound' => Barang::whereDate('tanggal_masuk', today())->count(),
            'draftCount' => Barang::where('status', Barang::STATUS_STUFFING)->count(),
            'openContainers' => Container::where('status', 'open')->with('barangs')->get(),
            'recentBarangs' => Barang::with('dimensions')->latest()->take(10)->get(),
        ]);
    }

    private function gudangIndoDashboard(): View
    {
        return view('dashboard.gudang-indo', [
            'arrivedContainers' => Container::where('status', 'arrived')->count(),
            'totalToDeliver' => Barang::where('status', Barang::STATUS_ETA)->count(),
            'deliveredToday' => Barang::where('status', Barang::STATUS_BONGKAR)->whereDate('updated_at', today())->count(),
        ]);
    }

    public function storeTask(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage task'), 403);

        $request->validate([
            'barang_id' => 'required|exists:barangs,id',
            'assigned_to' => 'nullable|exists:users,id',
            'tasks' => 'required|array|min:1',
            'tasks.*' => 'required|string|max:255',
        ]);

        $maxSort = BarangTask::where('barang_id', $request->barang_id)->max('sort_order') ?? 0;
        $created = [];

        foreach ($request->tasks as $title) {
            $title = trim($title);
            if ($title === '') continue;
            $maxSort++;
            $created[] = BarangTask::create([
                'barang_id' => $request->barang_id,
                'title' => $title,
                'assigned_to' => $request->assigned_to,
                'sort_order' => $maxSort,
            ]);
        }

        return response()->json([
            'success' => true,
            'tasks' => $created,
            'assignee_name' => $request->assigned_to
                ? User::find($request->assigned_to)?->name
                : null,
        ]);
    }

    public function toggleTask(BarangTask $barangTask): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage task'), 403);

        $barangTask->update([
            'is_done' => !$barangTask->is_done,
            'done_by' => !$barangTask->is_done ? auth()->id() : null,
            'done_at' => !$barangTask->is_done ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'is_done' => $barangTask->is_done,
        ]);
    }

    public function updateTask(Request $request, BarangTask $barangTask): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage task'), 403);

        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $barangTask->update(['title' => trim($request->title)]);

        return response()->json([
            'success' => true,
            'title' => $barangTask->title,
        ]);
    }

    public function deleteTask(BarangTask $barangTask): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage task'), 403);

        $barangTask->delete();

        return response()->json(['success' => true]);
    }
}
