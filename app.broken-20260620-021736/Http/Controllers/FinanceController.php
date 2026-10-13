<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Invoice::with('customer', 'items', 'payments.reviewer');

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($request->get('pay_status') === 'has_pending') {
            $query->whereHas('payments', fn ($q) => $q->where('status', 'pending'));
        } elseif ($request->get('pay_status') === 'no_payment') {
            $query->whereDoesntHave('payments');
        }

        $invoices = $query->latest()->paginate(20);
        $customers = User::role('customer')->get();

        $stats = [
            'total' => Invoice::count(),
            'unpaid' => Invoice::whereIn('status', ['draft', 'sent'])->count(),
            'pending_review' => \App\Models\Payment::where('status', 'pending')->count(),
            'paid' => Invoice::where('status', 'paid')->count(),
        ];

        return view('finance.index', compact('invoices', 'customers', 'stats'));
    }

    public function pricing(Request $request): View
    {
        $query = Barang::with('customer', 'containers');

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('no_index', 'like', "%{$search}%")
                ->orWhere('marking', 'like', "%{$search}%")
                ->orWhere('no_po', 'like', "%{$search}%")
                ->orWhereHas('containers', fn ($c) => $c->where('code', 'like', "%{$search}%")));
        }

        if ($from = $request->get('start_load_date')) {
            $query->whereHas('containers', fn ($q) => $q->whereDate('load_date', '>=', $from));
        }
        if ($to = $request->get('end_load_date')) {
            $query->whereHas('containers', fn ($q) => $q->whereDate('load_date', '<=', $to));
        }

        if ($show = $request->get('show')) {
            if ($show === 'priced') {
                $query->where('price_per_cbm', '>', 0);
            } elseif ($show === 'unpriced') {
                $query->where('price_per_cbm', '<=', 0);
            }
        }

        $barangs = $query->latest()->paginate(20);

        return view('finance.pricing', compact('barangs'));
    }

    public function reprice(Request $request, Barang $barang): JsonResponse
    {
        $old = $barang->only(['price_per_cbm', 'extra_charge']);

        $data = $request->validate([
            'price_per_cbm' => 'required|numeric|min:0',
            'extra_charge' => 'nullable|numeric|min:0',
        ]);

        $barang->update($data);
        ActivityLog::record('Barang repriced', $barang, $old, $data);

        return response()->json(['success' => true, 'message' => 'Harga berhasil diperbarui.']);
    }

    public function generateInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'barang_ids' => 'required|array|min:1',
            'barang_ids.*' => 'exists:barangs,id',
            'price_per_cbm' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
        ]);

        $invoice = DB::transaction(function () use ($data) {
            $barangs = Barang::whereIn('id', $data['barang_ids'])
                ->where('user_id', $data['user_id'])
                ->get();

            $explicitDue = ! empty($data['due_date']) ? (string) $data['due_date'] : null;
            $due = \App\Support\InvoiceDueResolver::resolveForBarangs($barangs, $explicitDue);

            $invoice = Invoice::create([
                'invoice_no' => Invoice::generateNumber(),
                'user_id' => $data['user_id'],
                'price_per_cbm' => $data['price_per_cbm'],
                'status' => \App\Support\InvoiceDueResolver::statusForResolved($due, 'sent'),
                'due_date' => $due['due_date'],
                'created_by' => auth()->id(),
            ]);

            foreach ($barangs as $barang) {
                if ($barang->invoiceItems()->exists()) continue;

                $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'barang_id' => $barang->id,
                    'cbm' => $barang->total_cbm,
                    'price_per_cbm' => $data['price_per_cbm'],
                    'insurance' => $insuranceAmount,
                    'extra' => (float) $barang->extra_charge,
                ]);
            }

            $invoice->recalculate();
            return $invoice;
        });

        ActivityLog::record('Invoice generated', $invoice);

        return response()->json(['success' => true, 'message' => "Invoice {$invoice->invoice_no} berhasil dibuat.", 'data' => $invoice->load('items')]);
    }

    public function showInvoice(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load('items.barang', 'customer', 'payments'));
    }

    public function customerBarangs(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $barangs = Barang::where('user_id', $request->user_id)
            ->whereDoesntHave('invoiceItems')
            ->select('id', 'no_index', 'marking', 'total_cbm', 'ctns', 'price_per_cbm', 'extra_charge', 'status')
            ->get();

        return response()->json($barangs);
    }
}
