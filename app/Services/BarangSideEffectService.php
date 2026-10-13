<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;

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
}
