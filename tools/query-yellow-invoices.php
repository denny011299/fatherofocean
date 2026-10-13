<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$markings = ['EK/ON/ARIASBY', 'EK/ON/GAXYB74'];
foreach ($markings as $marking) {
    echo "=== {$marking} ===\n";
    $barangs = DB::table('barangs')->where('marking', $marking)->get([
        'id', 'no_index', 'marking', 'status', 'cbm_type', 'price_per_cbm', 'weight_price',
        'total_cbm', 'total_gross_weight', 'approval_status',
    ]);
    echo 'barangs='.$barangs->count()."\n";
    foreach ($barangs as $b) {
        $invItems = DB::table('invoice_items')->where('barang_id', $b->id)->get();
        $invoiceIds = $invItems->pluck('invoice_id')->unique()->values();
        $invoices = $invoiceIds->isEmpty()
            ? collect()
            : DB::table('invoices')->whereIn('id', $invoiceIds)->get(['id', 'invoice_no', 'status', 'deleted_at']);
        echo "barang#{$b->id} index={$b->no_index} status={$b->status} approval={$b->approval_status} cbm={$b->total_cbm} price_cbm={$b->price_per_cbm}\n";
        echo '  invoice_items='.$invItems->count().' invoices='.json_encode($invoices)."\n";
        $containers = DB::table('barang_container')->where('barang_id', $b->id)->pluck('container_id');
        echo '  containers='.$containers->implode(',')."\n";
    }
}

// Also search soft variants
echo "\n=== LIKE search ===\n";
foreach (['ARIASBY', 'GAXYB74'] as $needle) {
    $rows = DB::table('barangs')->where('marking', 'like', "%{$needle}%")->get(['id', 'no_index', 'marking', 'status']);
    foreach ($rows as $r) {
        $has = DB::table('invoice_items')->where('barang_id', $r->id)->exists();
        echo "{$r->marking} #{$r->id} {$r->no_index} status={$r->status} has_invoice=".($has ? 'yes' : 'no')."\n";
    }
}

echo "\n=== Partai 1054 / container ===\n";
$containers = DB::table('containers')->where('partai', 'like', '%1054%')->get(['id', 'partai', 'container_no', 'status']);
foreach ($containers as $c) {
    echo "container#{$c->id} partai={$c->partai} no={$c->container_no} status={$c->status}\n";
    $barangIds = DB::table('barang_container')->where('container_id', $c->id)->pluck('barang_id');
    $withInv = DB::table('invoice_items')->whereIn('barang_id', $barangIds)->distinct('barang_id')->count('barang_id');
    echo '  barangs='.$barangIds->count()." with_invoice_items={$withInv}\n";
    $missing = DB::table('barangs')
        ->whereIn('id', $barangIds)
        ->whereNotIn('id', DB::table('invoice_items')->select('barang_id'))
        ->get(['id', 'no_index', 'marking', 'status']);
    foreach ($missing as $m) {
        echo "  MISSING INV: #{$m->id} {$m->no_index} {$m->marking} status={$m->status}\n";
    }
}
