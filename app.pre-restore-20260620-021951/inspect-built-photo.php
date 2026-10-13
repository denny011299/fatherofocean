<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Services\Exports\PackingListSpreadsheetBuilder;

$product = new BarangProduct([
    'product_name' => 'Sample',
    'ctns' => 1,
    'pcs_per_box' => 1,
    'price_per_unit_rmb' => 1,
    'gross_weight_kg' => 1,
    'net_weight_kg' => 1,
    'photo_path' => null,
]);
$barang = new Barang(['marking' => 'X']);
$barang->setRelation('products', collect([$product]));
$container = new Container(['code' => 'T']);
$container->setRelation('barangs', collect([$barang]));
$container->setRelation('marking', null);

$invoice = (new PackingListSpreadsheetBuilder())->build($container)->getSheetByName('发票');

foreach (['J1', 'K1', 'L1', 'J2', 'K2', 'L2', 'L4'] as $c) {
    $st = $invoice->getStyle($c);
    $fill = $st->getFill();
    echo "{$c} val=" . json_encode($invoice->getCell($c)->getValue())
        . ' bold=' . ($st->getFont()->getBold() ? 'Y' : 'N')
        . ' h=' . $st->getAlignment()->getHorizontal()
        . ' v=' . $st->getAlignment()->getVertical()
        . ' fill=' . $fill->getFillType()
        . ' rgb=' . $fill->getStartColor()->getRGB() . PHP_EOL;
}

foreach ($invoice->getMergeCells() as $r) {
    if (preg_match('/^L\d/', $r) || preg_match('/:L\d/', $r)) {
        echo "merge: {$r}\n";
    }
}
