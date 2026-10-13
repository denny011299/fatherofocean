<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;

final class BarangSideEffectService
{
    public function afterStore(int $barangId, ?int $actorId): void
    {
        $barang = Barang::query()->find($barangId);
        if (! $barang) {
            return;
        }

        ActivityLog::record('Barang created', $barang);
        app(BarangTrackingService::class)->syncStuffing($barang, $actorId);

        $invoice = $this->createInvoiceForBarang($barang->fresh());
        if ($invoice) {
            ActivityLog::record('Invoice generated', $invoice);
        }
    }

    /** @param array<string, mixed> $old */
    /** @param array<string, mixed> $new */
    public function afterUpdate(int $barangId, array $old, array $new): void
    {
        $barang = Barang::query()->find($barangId);
        if (! $barang) {
            return;
        }

        ActivityLog::record('Barang updated', $barang, $old, $new);
    }

    /** @param list<int|string> $invoiceIds */
    /** @param array<string, mixed> $snapshot */
    public function afterDestroy(array $invoiceIds, array $snapshot): void
    {
        ActivityLog::record('Barang deleted', null, $snapshot);

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()->find((int) $invoiceId);
            if (! $invoice) {
                continue;
            }

            $invoice->recalculate();
            if (! $invoice->items()->exists()) {
                $invoice->delete();
            }
        }
    }

    private function createInvoiceForBarang(Barang $barang): ?Invoice
    {
        if (! $barang->user_id || $barang->invoiceItems()->exists()) {
            return null;
        }

        $pricePerCbm = (float) ($barang->price_per_cbm ?? 0);
        if ($pricePerCbm <= 0) {
            return null;
        }

        $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
        $due = \App\Support\InvoiceDueResolver::resolveForBarang($barang);

        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateNumber(),
            'user_id' => $barang->user_id,
            'price_per_cbm' => $pricePerCbm,
            'status' => \App\Support\InvoiceDueResolver::statusForResolved($due, 'draft'),
            'due_date' => $due['due_date'],
            'created_by' => auth()->id(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'barang_id' => $barang->id,
            'cbm' => $barang->total_cbm,
            'price_per_cbm' => $pricePerCbm,
            'insurance' => $insuranceAmount,
            'extra' => (float) ($barang->extra_charge ?? 0),
        ]);

        $invoice->recalculate();

        return $invoice;
    }
}
