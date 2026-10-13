<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Barang;
use Illuminate\Console\Command;

final class RecalculateBarangTotalsCommand extends Command
{
    protected $signature = 'barangs:recalculate-totals
                            {--barang= : Hanya barang ID tertentu}
                            {--sync-invoices : Paksa sync invoice draft/sent (default: sudah ikut di recalculateTotals)}';

    protected $description = 'Hitung ulang volume_cbm produk + total_cbm barang (jumlah CBM per baris) dan sync invoice terbuka';

    public function handle(): int
    {
        $barangId = $this->option('barang');

        $query = Barang::query()
            ->whereHas('products', static fn ($q) => $q->whereNull('parent_id'));

        if ($barangId !== null && $barangId !== '') {
            $query->whereKey((int) $barangId);
        }

        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($barangs) use (&$count): void {
            foreach ($barangs as $barang) {
                $before = (float) $barang->total_cbm;
                $barang->recalculateTotals();
                $after = (float) $barang->total_cbm;
                if (abs($before - $after) > 0.0005) {
                    $this->line("Barang #{$barang->id}: {$before} → {$after}");
                }
                $count++;
            }
        });

        $this->info("Selesai: {$count} barang dihitung ulang (invoice draft/sent tanpa pembayaran ikut diselaraskan).");

        return self::SUCCESS;
    }
}
