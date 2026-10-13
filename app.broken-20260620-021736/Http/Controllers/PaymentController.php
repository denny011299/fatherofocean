<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = Payment::with('invoice', 'customer', 'reviewer')
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return view('payment.index', compact('payments'));
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json($payment->load('invoice.customer', 'reviewer'));
    }

    public function approve(Payment $payment): JsonResponse
    {
        if ($payment->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Pembayaran sudah diproses sebelumnya.'], 422);
        }

        DB::transaction(function () use ($payment) {
            $payment->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $invoice = $payment->invoice;
            if ($invoice) {
                $totalPaid = $invoice->payments()->where('status', 'approved')->sum('amount');
                if ($totalPaid >= (float) $invoice->grand_total) {
                    $invoice->update(['status' => 'paid']);
                }
            }

            ActivityLog::record('Payment approved', $payment);
        });

        return response()->json(['success' => true, 'message' => 'Pembayaran berhasil di-approve.']);
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Pembayaran sudah diproses sebelumnya.'], 422);
        }

        $data = $request->validate([
            'review_notes' => 'required|string|max:500',
        ]);

        $payment->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'review_notes' => $data['review_notes'],
            'reviewed_at' => now(),
        ]);

        ActivityLog::record('Payment rejected', $payment, null, $data);

        return response()->json(['success' => true, 'message' => 'Pembayaran ditolak.']);
    }
}
