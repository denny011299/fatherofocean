<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/../../resources/excel/packing-list-commercial-invoice-template.xls';
$s = IOFactory::createReader('Xls')->load($path)->getSheetByName('发票');
$s->removeRow(1, 12);
$s->setCellValue('L1', '');
$s->setCellValue('L2', '');
$s->removeColumn('L');

echo 'Merges: ' . implode(', ', array_keys($s->getMergeCells())) . PHP_EOL;

foreach (['J1', 'K1', 'L1', 'J2', 'K2', 'L2', 'I1', 'I2'] as $c) {
    if (! $s->cellExists($c)) {
        echo "{$c} N/A\n";
        continue;
    }
    $st = $s->getStyle($c);
    $fill = $st->getFill();
    $val = $s->getCell($c)->getValue();
    echo "{$c} val=" . json_encode($val)
        . ' bold=' . ($st->getFont()->getBold() ? 'Y' : 'N')
        . ' h=' . $st->getAlignment()->getHorizontal()
        . ' v=' . $st->getAlignment()->getVertical()
        . ' fill=' . $fill->getFillType()
        . ' rgb=' . $fill->getStartColor()->getRGB() . PHP_EOL;
}

foreach ($s->getMergeCells() as $r) {
    if (str_contains($r, 'L')) {
        echo "merge: {$r}\n";
    }
}
