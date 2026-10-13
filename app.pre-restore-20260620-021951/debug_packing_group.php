<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$c = App\Models\Container::with('barangs.dimensions', 'barangs.customer')
    ->whereHas('barangs')
    ->first();

if (! $c) {
    echo "no container\n";
    exit(0);
}

$barangsByMarking = $c->barangs
    ->sortBy(fn ($barang) => [$barang->marking ?? '', $barang->id])
    ->groupBy(fn ($barang) => $barang->marking ?? '')
    ->map(fn ($barangs) => $barangs->values())
    ->sortKeys();

foreach ($barangsByMarking as $marking => $barangs) {
    echo 'MARKING=[' . $marking . '] count=' . $barangs->count() . PHP_EOL;
    foreach ($barangs as $i => $b) {
        echo '  idx=' . $i . ' first=' . ($i === 0 ? 'yes' : 'no') . ' ' . $b->no_index . ' marking=' . ($b->marking ?? 'null') . PHP_EOL;
    }
}
