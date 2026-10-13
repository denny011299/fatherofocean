<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$path = __DIR__ . '/../../resources/excel/packing-list-commercial-invoice-template.xls';
$s = IOFactory::createReader('Xls')->load($path)->getSheetByName('发票');

// Before header strip - rows 13-20 are data area in template
for ($row = 13; $row <= 20; $row++) {
    foreach (['J', 'K', 'L', 'M', 'N'] as $col) {
        $c = $col . $row;
        if (! $s->cellExists($c)) {
            continue;
        }
        $fill = $s->getStyle($c)->getFill();
        if ($fill->getFillType() === Fill::FILL_SOLID) {
            echo "{$c} rgb=" . $fill->getStartColor()->getRGB() . PHP_EOL;
        }
    }
}

echo "--- after strip ---\n";
$s->removeRow(1, 12);
for ($row = 4; $row <= 8; $row++) {
    foreach (['J', 'K', 'L', 'M', 'N'] as $col) {
        $c = $col . $row;
        if (! $s->cellExists($c)) {
            continue;
        }
        $fill = $s->getStyle($c)->getFill();
        if ($fill->getFillType() === Fill::FILL_SOLID) {
            echo "{$c} rgb=" . $fill->getStartColor()->getRGB() . PHP_EOL;
        }
    }
}
