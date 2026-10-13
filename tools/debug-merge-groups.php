<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use ReflectionMethod;

$barang = new Barang(['id' => 1, 'no_index' => 'testing 1', 'marking' => '']);
$parent = new BarangProduct(['id' => 10, 'parent_id' => null, 'product_name' => 'test', 'ctns' => 2]);
$sub1 = new BarangProduct(['id' => 11, 'parent_id' => 10, 'product_name' => 'test.2.0']);
$sub2 = new BarangProduct(['id' => 12, 'parent_id' => 10, 'product_name' => 'test.1.0']);
$parent2 = new BarangProduct(['id' => 20, 'parent_id' => null, 'product_name' => 'testing3.1', 'ctns' => 2]);
$barang->setRelation('products', new EloquentCollection([$parent, $sub1, $sub2, $parent2]));

$export = new BarangListProductsExport(new EloquentCollection([$barang]));
$mg = new ReflectionMethod($export, 'resolveMergeGroup');
$mg->setAccessible(true);
foreach ([$parent, $sub1, $sub2, $parent2] as $p) {
    echo $p->product_name.' => '.$mg->invoke($export, $barang, $p, 'product').PHP_EOL;
}
