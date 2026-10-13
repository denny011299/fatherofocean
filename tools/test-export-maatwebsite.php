<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Maatwebsite\Excel\Facades\Excel;

$barang = new Barang(['no_index' => 't', 'marking' => 'BS/TEST']);
$barang->id = 1;
$parent = new \App\Models\BarangProduct(['parent_id' => null, 'product_name' => 'x', 'ctns' => 2, 'pcs_per_box' => 2, 'length_cm' => 14, 'width_cm' => 15, 'height_cm' => 6, 'gross_weight_kg' => 13]);
$parent->id = 10;
$sub = new \App\Models\BarangProduct(['parent_id' => 10, 'product_name' => 'sub', 'pcs_per_box' => 20]);
$sub->id = 11;
$barang->setRelation('products', new EloquentCollection([$parent, $sub]));

Excel::store(new BarangListProductsExport(new EloquentCollection([$barang])), 'testing/full-export.xlsx');
echo "OK\n";
