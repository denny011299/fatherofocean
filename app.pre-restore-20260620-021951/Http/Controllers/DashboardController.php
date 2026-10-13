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
use App\Support\CompanyRevenueSettings;
use App\Support\CustomerPortalScope;
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
        abort_unless(auth()->user()?->can('view dashboard'), 403);

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
        abort_unless(auth()->user()?->can('view dashboard'), 403);
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        [$dateFrom, $dateTo, $year, $month] = $this->resolveCompanyValuePeriod($request);
        $totals = SalesRevenueCalculator::totalsForUser(null, $dateFrom, $dateTo);
        $totalValue = (float) ($totals['estimasi_company'] + $totals['fix_company']);

        [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod($year, $month);
        $lastMonthTotals = SalesRevenueCalculator::totalsForUser(null, $lastFrom, $lastTo);
        $lastMonthValue = (float) ($lastMonthTotals['estimasi_company'] + $lastMonthTotals['fix_company']);
        $lastMonthFormatted = number_format($lastMonthValue, 0, ',', '.');

        return response()->json([
            'year' => $year,
            'month' => $month,
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

    /** Admin dashboard (global or per-marking for sales). */
    private function staffDashboard(Request $request, ?User $scopedUser = null, bool $isSales = false): View
    {
        $user = auth()->user();
        $onTheWayStatuses = [Barang::STATUS_ETD, Barang::STATUS_ETA];
        $scoped = $scopedUser !== null;

        $kirimanQuery = Barang::whereIn('status', $onTheWayStatuses);
        $tagihanQuery = Invoice::whereIn('status', ['draft', 'sent']);
        $todayBarangQuery = Barang::with('customer', 'tasks.assignee', 'products')->whereDate('tanggal_masuk', today());
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
        $kritikQuery = Rating::with('user', 'barang', 'assignee')
            ->whereNotNull('review')
            ->where('review', '!=', '');

        if ($scoped) {
            Marking::restrictBarangsQuery($kirimanQuery, $scopedUser);
            $tagihanQuery->whereHas(
                'items',
                fn ($q) => $q->whereHas(
                    'barang',
                    fn ($bq) => Marking::restrictBarangsQuery($bq, $scopedUser),
                ),
            );
            Marking::restrictBarangsQuery($todayBarangQuery, $scopedUser);
            Marking::restrictBarangsQuery($taskBarangQuery, $scopedUser);
            $kritikQuery->where('assigned_to', $user->id);
        }

        $shipmentChartYear = max(1999, min(now()->year, (int) $request->query('chart_year', now()->year)));
        $monthlyShipments = $this->getMonthlyShipmentsForYear($shipmentChartYear, $scopedUser);
        $shipmentYears = $this->getShipmentYears($scopedUser);

        $staffList = $isSales
            ? collect()
            : User::role(['admin_cs', 'sales', 'admin_gudang_china', 'admin_gudang_indo', 'finance'])
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

        $salesRevenue = $isSales
            ? SalesRevenueCalculator::totalsForUser($scopedUser)
            : null;

        $isSuperAdmin = $user->hasRole('super_admin');
        [$companyDateFrom, $companyDateTo, $companyFilterYear, $companyFilterMonth] = $this->resolveCompanyValuePeriod($request);
        $companyRevenue = $isSuperAdmin
            ? SalesRevenueCalculator::totalsForUser(null, $companyDateFrom, $companyDateTo)
            : null;

        $companyRevenueLastMonth = null;
        $companyRevenueLastMonthLabel = null;
        if ($isSuperAdmin) {
            [$lastFrom, $lastTo, $lastMonthRef] = $this->resolveCompanyValueLastMonthPeriod(
                $companyFilterYear,
                $companyFilterMonth,
            );
            $lastMonthTotals = SalesRevenueCalculator::totalsForUser(null, $lastFrom, $lastTo);
            $companyRevenueLastMonth = (float) ($lastMonthTotals['estimasi_company'] + $lastMonthTotals['fix_company']);
            $companyRevenueLastMonthLabel = $lastMonthRef->translatedFormat('M Y');
        }

        return view('dashboard.admin', [
            'totalKirimanBerjalan' => $kirimanQuery->count(),
            'totalMasterMarking' => $scoped
                ? Marking::visibleToUser($user)->count()
                : Marking::count(),
            'totalTagihan' => $tagihanQuery->count(),
            'totalOmset' => $isSales ? 0 : $this->calculatePaidOmset($scopedUser),
            'salesRevenue' => $salesRevenue,
            'companyRevenue' => $companyRevenue,
            'companyRevenueLastMonth' => $companyRevenueLastMonth,
            'companyRevenueLastMonthLabel' => $companyRevenueLastMonthLabel,
            'companyRevenueFixed' => $isSuperAdmin ? CompanyRevenueSettings::fixedAmount() : null,
            'companyFilterYear' => $companyFilterYear,
            'companyFilterMonth' => $companyFilterMonth,
            'monthlyShipments' => $monthlyShipments,
            'shipmentChartYear' => $shipmentChartYear,
            'shipmentYears' => $shipmentYears,
            'kritikSaran' => $kritikQuery->latest()->take(10)->get(),
            'todayBarangs' => $todayBarangQuery->latest()->get(),
            'taskBarangOptions' => $taskBarangQuery->get(),
            'staffList' => $staffList,
            'isSales' => $isSales,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    /** Paid (lunas) omset from invoice line items — matches export omset report. */
    private function calculatePaidOmset(?User $scopedUser): float
    {
        $query = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', 'paid');

        if ($scopedUser !== null) {
            $barangSubquery = Barang::query()->select('id');
            Marking::restrictBarangsQuery($barangSubquery, $scopedUser);
            $query->whereIn('invoice_items.barang_id', $barangSubquery);
        }

        return (float) $query->sum('invoice_items.line_total');
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
            'myInvoices' => (clone $invoiceQuery)->with('payments')->latest()->take(5)->get(),
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
