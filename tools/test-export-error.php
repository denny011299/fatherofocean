<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use ReflectionMethod;

$path = 'resources/excel/daftar-kirim-export-template.xlsx';
$sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
foreach (array_keys($sheet->getMergeCells()) as $mergeRange) {
    if (!preg_match('/[A-Z]+(\d+)(?::[A-Z]+(\d+))?/', $mergeRange, $matches)) {
        echo "NO MATCH: $mergeRange\n";
    } elseif (!isset($matches[1])) {
        echo "NO KEY1: $mergeRange\n";
    }
}

$barang = new Barang(['no_index' => 't', 'marking' => '']);
$barang->id = 1;
$p = new \App\Models\BarangProduct(['parent_id' => null, 'product_name' => 'x', 'ctns' => 1, 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1, 'gross_weight_kg' => 1]);
$p->id = 1;
$barang->setRelation('products', new EloquentCollection([$p]));

$export = new BarangListProductsExport(new EloquentCollection([$barang]));
$m = new ReflectionMethod($export, 'buildSpreadsheet');
$m->setAccessible(true);
$m->invoke($export);
echo "OK\n";
