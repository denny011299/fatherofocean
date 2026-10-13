<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/../../resources/excel/packing-list-commercial-invoice-template.xls';
$s = IOFactory::createReader('Xls')->load($path)->getSheetByName('发票');
$s->removeRow(1, 12);

foreach (['J1', 'K1', 'L1', 'M1', 'N1', 'L2', 'M2', 'L4', 'M4', 'N4'] as $c) {
    if (! $s->cellExists($c)) {
        echo "{$c} N/A\n";
        continue;
    }
    $st = $s->getStyle($c);
    $fill = $st->getFill();
    echo "{$c} val=" . json_encode($s->getCell($c)->getValue())
        . ' bold=' . ($st->getFont()->getBold() ? 'Y' : 'N')
        . ' h=' . $st->getAlignment()->getHorizontal()
        . ' fill=' . $fill->getFillType()
        . ' rgb=' . $fill->getStartColor()->getRGB() . PHP_EOL;
}

foreach ($s->getMergeCells() as $r) {
    if (preg_match('/L\d/', $r)) {
        echo "merge: {$r}\n";
    }
}
