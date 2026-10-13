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
use App\Support\CustomerPortalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        if ($user->hasRole('super_admin') || $user->hasRole('supervisor') || $user->hasRole('admin_cs')) {
            return $this->adminDashboard();
        }

        if ($user->hasRole('sales')) {
            return $this->salesDashboard();
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

        return $this->adminDashboard();
    }

    private function adminDashboard(): View
    {
        $onTheWayStatuses = ['shipped', 'in_transit', 'out_for_delivery'];

        $monthlyShipments = $this->getMonthlyShipments();

        $staffList = User::role(['admin_cs', 'sales', 'admin_gudang_china', 'admin_gudang_indo', 'finance'])
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return view('dashboard.admin', [
            'totalKirimanBerjalan' => Barang::whereIn('status', $onTheWayStatuses)->count(),
            'totalMasterMarking' => Marking::count(),
            'totalTagihan' => Invoice::whereIn('status', ['draft', 'sent'])->count(),
            'totalOmset' => Invoice::where('status', 'paid')->sum('grand_total'),
            'monthlyShipments' => $monthlyShipments,
            'kritikSaran' => Rating::with('user', 'barang', 'assignee')
                ->whereNotNull('review')
                ->where('review', '!=', '')
                ->latest()
                ->take(10)
                ->get(),
            'todayBarangs' => Barang::with('customer', 'tasks.assignee')
                ->whereDate('tanggal_masuk', today())
                ->latest()
                ->get(),
            'staffList' => $staffList,
            'isSales' => false,
        ]);
    }

    private function salesDashboard(): View
    {
        $user = auth()->user();
        $onTheWayStatuses = ['shipped', 'in_transit', 'out_for_delivery'];

        $myMarkingCodes = Marking::where('sales_id', $user->id)->pluck('code');

        $monthlyShipments = $this->getMonthlyShipments($myMarkingCodes->toArray());

        return view('dashboard.admin', [
            'totalKirimanBerjalan' => Barang::whereIn('status', $onTheWayStatuses)
                ->whereIn('marking', $myMarkingCodes)
                ->count(),
            'totalMasterMarking' => Marking::where('sales_id', $user->id)->count(),
            'totalTagihan' => Invoice::whereIn('status', ['draft', 'sent'])
                ->whereHas('items', fn ($q) => $q->whereHas('barang', fn ($bq) => $bq->whereIn('marking', $myMarkingCodes)))
                ->count(),
            'totalOmset' => Invoice::where('status', 'paid')
                ->whereHas('items', fn ($q) => $q->whereHas('barang', fn ($bq) => $bq->whereIn('marking', $myMarkingCodes)))
                ->sum('grand_total'),
            'monthlyShipments' => $monthlyShipments,
            'kritikSaran' => Rating::with('user', 'barang', 'assignee')
                ->whereNotNull('review')
                ->where('review', '!=', '')
                ->where('assigned_to', $user->id)
                ->latest()
                ->take(10)
                ->get(),
            'todayBarangs' => Barang::with('customer', 'tasks.assignee')
                ->whereIn('marking', $myMarkingCodes)
                ->whereDate('tanggal_masuk', today())
                ->latest()
                ->get(),
            'staffList' => collect(),
            'isSales' => true,
        ]);
    }

    public function assignFeedback(Request $request, Rating $rating): JsonResponse
    {
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

    private function getMonthlyShipments(array $markingCodes = []): \Illuminate\Support\Collection
    {
        $query = Barang::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(*) as total')
        )
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth());

        if (!empty($markingCodes)) {
            $query->whereIn('marking', $markingCodes);
        }

        $monthlyShipments = $query->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $months = collect();
        for ($i = 11; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $months[$key] = $monthlyShipments[$key] ?? 0;
        }

        return $months;
    }

    private function customerDashboard(): View
    {
        $user = auth()->user();

        return view('dashboard.customer', [
            'myBarangs' => Barang::where('user_id', $user->id)->with('latestTracking', 'rating', 'containers')->latest()->take(10)->get(),
            'myInvoices' => Invoice::where('user_id', $user->id)->latest()->take(5)->get(),
            'totalBarang' => Barang::where('user_id', $user->id)->count(),
            'unpaidInvoices' => Invoice::where('user_id', $user->id)->whereIn('status', ['draft', 'sent'])->count(),
            'deliveredCount' => Barang::where('user_id', $user->id)->where('status', 'delivered')->count(),
        ]);
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
            'draftCount' => Barang::where('status', 'draft')->count(),
            'openContainers' => Container::where('status', 'open')->with('barangs')->get(),
            'recentBarangs' => Barang::with('dimensions')->latest()->take(10)->get(),
        ]);
    }

    private function gudangIndoDashboard(): View
    {
        return view('dashboard.gudang-indo', [
            'arrivedContainers' => Container::where('status', 'arrived')->count(),
            'totalToDeliver' => Barang::where('status', 'arrived')->count(),
            'deliveredToday' => Barang::where('status', 'delivered')->whereDate('updated_at', today())->count(),
        ]);
    }

    public function storeTask(Request $request): JsonResponse
    {
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
        $barangTask->delete();

        return response()->json(['success' => true]);
    }
}
