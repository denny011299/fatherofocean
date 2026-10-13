<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::first();
if ($user) {
    auth()->login($user);
}

$c = App\Models\Container::with('barangs.dimensions', 'barangs.customer', 'vessel', 'creator')
    ->whereHas('barangs')
    ->first();

$barangsByMarking = $c->barangs
    ->sortBy(fn ($barang) => [$barang->marking ?? '', $barang->id])
    ->groupBy(fn ($barang) => $barang->marking ?? '')
    ->map(fn ($barangs) => $barangs->values())
    ->sortKeys();

$html = view('container.detail', [
    'container' => $c,
    'barangsByMarking' => $barangsByMarking,
    'usedVolume' => $c->used_volume,
    'remainingVolume' => $c->remaining_volume,
    'capacityPercent' => $c->capacity_percent,
    'capacityLevel' => $c->capacity_level,
])->render();

if (! preg_match('/<table[^>]*id="packingTable"[\s\S]*?<\/table>/', $html, $m)) {
    echo "table not found\n";
    exit(1);
}

$table = $m[0];
preg_match_all('/<tbody>[\s\S]*?<\/tbody>/', $table, $tb);
$tbody = $tb[0][0] ?? '';
preg_match_all('/<tr>[\s\S]*?<\/tr>/', $tbody, $rows);
foreach ($rows[0] as $i => $row) {
    $td = substr_count($row, '<td');
    $hasMark = str_contains($row, 'col-mark');
    $hasRowspan = str_contains($row, 'rowspan');
    $index = '';
    if (preg_match('/>(BRG-[^<]+)</', $row, $mm)) {
        $index = $mm[1];
    }
    echo 'row ' . ($i + 1) . ' index=' . $index . ' tds=' . $td . ' mark=' . ($hasMark ? 'yes' : 'no') . ' rowspan=' . ($hasRowspan ? 'yes' : 'no') . PHP_EOL;
}
