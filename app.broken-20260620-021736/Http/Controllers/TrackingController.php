<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\TrackingStatus;
use App\Support\CustomerPortalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class TrackingController extends Controller
{
    // ── CUSTOMER: lacak barang milik sendiri ──
    public function customerIndex(Request $request): View
    {
        $user = auth()->user();
        $query = Barang::where('user_id', $user->id)->with('latestTracking', 'containers', 'rating');

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('no_index', 'like', "%{$search}%")
                ->orWhere('marking', 'like', "%{$search}%"));
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $barangs = $query->latest()->paginate(15);
        $stats = [
            'total' => Barang::where('user_id', $user->id)->count(),
            'in_transit' => Barang::where('user_id', $user->id)->whereIn('status', ['loaded', 'shipped', 'in_transit'])->count(),
            'delivered' => Barang::where('user_id', $user->id)->where('status', 'delivered')->count(),
            'pending' => Barang::where('user_id', $user->id)->whereIn('status', ['draft', 'inbound'])->count(),
        ];

        return view('tracking.customer', [
            'barangs' => $barangs,
            'stats' => $stats,
            'portalMarking' => CustomerPortalScope::resolveMarking(),
        ]);
    }

    // ── STAFF: satu halaman tracking untuk semua role non-customer ──
    public function staffIndex(Request $request): View
    {
        $query = Barang::with('latestTracking', 'customer', 'containers');
        $this->applyFilters($query, $request);
        $barangs = $query->latest()->paginate(25);
        $stats = $this->getGlobalStats();

        return view('tracking.staff', compact('barangs', 'stats'));
    }

    // ── SHARED: get timeline for a barang ──
    public function show(Barang $barang): JsonResponse
    {
        if (auth()->user()->hasRole('customer') && ! CustomerPortalScope::barangVisible($barang)) {
            abort(403);
        }

        return response()->json([
            'barang' => $barang->load('customer', 'containers'),
            'timeline' => $barang->trackingStatuses()->with('creator')->get(),
        ]);
    }

    // ── Update status (admin CS, gudang indo, supervisor) ──
    public function updateStatus(Request $request, Barang $barang): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|max:50',
            'location' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        $old = $barang->status;

        DB::transaction(function () use ($data, $barang, $old) {
            TrackingStatus::create([
                'barang_id' => $barang->id,
                'status' => $data['status'],
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $barang->update(['status' => $data['status']]);
            ActivityLog::record('Tracking updated', $barang, ['status' => $old], ['status' => $data['status']]);
        });

        return response()->json(['success' => true, 'message' => 'Status tracking berhasil diperbarui.']);
    }

    // ── Billing (customer) ──
    public function billing(): View
    {
        $query = Invoice::query()->with('items.barang', 'payments');
        CustomerPortalScope::applyInvoiceScope($query);

        return view('tracking.billing', [
            'invoices' => $query->latest()->paginate(10),
            'portalMarking' => CustomerPortalScope::resolveMarking(),
        ]);
    }

    public function uploadPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'sender_name' => 'required|string|max:255',
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $user = auth()->user();
        $invoiceQuery = Invoice::where('id', $data['invoice_id']);

        if ($user->hasRole('customer')) {
            CustomerPortalScope::applyInvoiceScope($invoiceQuery, $user);
        }

        $invoice = $invoiceQuery->firstOrFail();

        $path = $request->file('proof_file')->store('payment-proofs', 'public');

        Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => $data['amount'],
            'sender_name' => $data['sender_name'],
            'proof_file' => $path,
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => 'Bukti pembayaran berhasil diunggah.']);
    }

    public function rate(Request $request, Barang $barang): JsonResponse
    {
        abort_unless($barang->user_id === auth()->id(), 403, 'Anda tidak bisa memberikan rating untuk barang ini.');

        $data = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        Rating::updateOrCreate(
            ['barang_id' => $barang->id, 'user_id' => auth()->id()],
            $data
        );

        return response()->json(['success' => true, 'message' => 'Terima kasih atas rating Anda!']);
    }

    // ── Public Tracking Portal (no auth) ──
    public function publicIndex(): View
    {
        return view('tracking.public-index');
    }

    public function publicSearch(Request $request): RedirectResponse
    {
        $request->validate([
            'marking_code' => 'required|string|max:100',
        ]);

        $code = trim($request->input('marking_code'));
        $marking = Marking::where('code', $code)->active()->first();

        if (!$marking) {
            return back()->withInput()->withErrors(['marking_code' => 'Kode marking tidak ditemukan atau tidak aktif.']);
        }

        return redirect()->route('track.show', $marking->code);
    }

    public function publicShow(string $markingCode): View
    {
        $marking = Marking::where('code', $markingCode)
            ->active()
            ->with(['customer', 'branch'])
            ->firstOrFail();

        $barangs = Barang::where('marking', $marking->code)
            ->with(['trackingStatuses.creator', 'containers', 'latestTracking', 'invoiceItems.invoice'])
            ->latest('tanggal_masuk')
            ->get();

        $invoices = Invoice::where('user_id', $marking->user_id)
            ->with('items.barang')
            ->latest()
            ->get()
            ->filter(fn ($inv) => $inv->items->pluck('barang.marking')->contains($marking->code));

        $latestUpdate = $barangs->pluck('latestTracking')->filter()->sortByDesc('created_at')->first();

        return view('tracking.public-show', compact('marking', 'barangs', 'invoices', 'latestUpdate'));
    }

    // ── Private helpers ──
    private function publicTrackMarkingFromSession(Request $request): ?string
    {
        $marking = $request->session()->get(self::PUBLIC_TRACK_MARKING_SESSION);

        return is_string($marking) && $marking !== '' ? $marking : null;
    }

    private function publicMarkingAccessRedirect(Request $request, string $marking): ?RedirectResponse
    {
        $sessionMarking = $this->publicTrackMarkingFromSession($request);

        if ($sessionMarking === null || $sessionMarking !== $marking) {
            return redirect()
                ->route('track.index', ['marking' => $marking])
                ->withErrors([
                    'marking_code' => 'Masukkan marking dan passcode untuk melihat tracking ini.',
                ]);
        }

        return null;
    }

    private function applyFilters($query, Request $request): void
    {
        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('no_index', 'like', "%{$search}%")
                ->orWhere('marking', 'like', "%{$search}%")
                ->orWhere('no_po', 'like', "%{$search}%"));
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($dest = $request->get('destination')) {
            $query->where('destination', $dest);
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('tanggal_masuk', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('tanggal_masuk', '<=', $to);
        }
    }

    private function getGlobalStats(): array
    {
        return [
            'total' => Barang::count(),
            'inbound' => Barang::where('status', 'inbound')->count(),
            'loaded' => Barang::where('status', 'loaded')->count(),
            'in_transit' => Barang::whereIn('status', ['shipped', 'in_transit'])->count(),
            'arrived' => Barang::where('status', 'arrived')->count(),
            'delivered' => Barang::where('status', 'delivered')->count(),
        ];
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            'draft' => 'Draft',
            'inbound' => 'Inbound',
            'loaded' => 'Loaded',
            'shipped' => 'Shipped',
            'in_transit' => 'In Transit',
            'arrived' => 'Arrived',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
        ];
    }
}
