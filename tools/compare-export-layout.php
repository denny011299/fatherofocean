<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionMethod;

$barang = new Barang([
    'id' => 1,
    'no_index' => 'testing 1',
    'marking' => 'BS/5758/SEA',
    'cbm_type' => 'Lartas Berat',
    'tanggal_masuk' => Carbon::parse('2026-06-24'),
]);

$parent = new BarangProduct([
    'id' => 10,
    'parent_id' => null,
    'product_name' => 'test',
    'ctns' => 2,
    'pcs_per_box' => 2,
    'length_cm' => 14,
    'width_cm' => 15,
    'height_cm' => 6,
    'gross_weight_kg' => 13,
]);

$sub1 = new BarangProduct([
    'id' => 11,
    'parent_id' => 10,
    'product_name' => 'test.2.0',
    'pcs_per_box' => 20,
]);

$sub2 = new BarangProduct([
    'id' => 12,
    'parent_id' => 10,
    'product_name' => 'test.1.0',
    'pcs_per_box' => 50,
]);

$parent2 = new BarangProduct([
    'id' => 20,
    'parent_id' => null,
    'product_name' => 'testing3.1',
    'ctns' => 2,
    'pcs_per_box' => 2,
    'length_cm' => 20,
    'width_cm' => 29,
    'height_cm' => 50,
    'gross_weight_kg' => 13,
]);

$barang->setRelation('products', new EloquentCollection([$parent, $sub1, $sub2, $parent2]));

$export = new BarangListProductsExport(new EloquentCollection([$barang]));
$method = new ReflectionMethod($export, 'buildSpreadsheet');
$method->setAccessible(true);
$spreadsheet = $method->invoke($export);

$path = __DIR__.'/../storage/app/testing/export-compare.xlsx';
@mkdir(dirname($path), 0755, true);
(new Xlsx($spreadsheet))->save($path);

$sheet = $spreadsheet->getSheetByName('Daftar Kirim');
for ($row = 3; $row <= 6; $row++) {
    $cells = [];
    foreach (range('A', 'R') as $col) {
        $v = $sheet->getCell($col.$row)->getValue();
        if ($v !== null && $v !== '') {
            $cells[$col] = $v;
        }
    }
    echo "R{$row}: ".json_encode($cells, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

echo PHP_EOL.'Merges touching data:'.PHP_EOL;
foreach ($sheet->getMergeCells() as $m) {
    if (preg_match('/(\d+)/', $m, $mm) && (int) $mm[1] >= 3) {
        echo $m.PHP_EOL;
    }
}
