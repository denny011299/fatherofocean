<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:/Users/denny/Downloads/Rekapan omset kontainer 3.xlsx';
$sheet = IOFactory::load($path)->getSheetByName('Omset');

echo "=== Rows 9-12 raw ===\n";
for ($r = 9; $r <= 12; $r++) {
    $cells = [];
    foreach (range('A', 'Z') as $col) {
        $v = $sheet->getCell($col.$r)->getValue();
        if ($v !== null && $v !== '') {
            $cells[] = $col.'='.(is_string($v) ? $v : (string) $v);
        }
    }
    foreach (['AA','AB','AC','AD','AE','AF','AG','AH','AI'] as $col) {
        $v = $sheet->getCell($col.$r)->getValue();
        if ($v !== null && $v !== '') {
            $cells[] = $col.'='.(is_string($v) ? $v : (string) $v);
        }
    }
    $fill = strtoupper((string) $sheet->getCell('D'.$r)->getStyle()->getFill()->getStartColor()->getRGB());
    echo "R{$r} fillD={$fill}: ".implode(' | ', $cells)."\n";
}

echo "\n=== End section rows 60-66 ===\n";
for ($r = 55; $r <= 66; $r++) {
    $cells = [];
    for ($c = 1; $c <= 35; $c++) {
        $letter = Coordinate::stringFromColumnIndex($c);
        $v = $sheet->getCell($letter.$r)->getValue();
        if ($v !== null && $v !== '') {
            $cells[] = $letter.'='.(is_string($v) ? str_replace(["\r","\n"],' ', $v) : (string) $v);
        }
    }
    if ($cells !== []) {
        echo "R{$r}: ".implode(' | ', $cells)."\n";
    }
}

echo "\n=== Tarif sheet ===\n";
$tarif = IOFactory::load($path)->getSheetByName('Tarif Kategori');
for ($r = 1; $r <= (int) $tarif->getHighestRow(); $r++) {
    $cells = [];
    for ($c = 1; $c <= 10; $c++) {
        $letter = Coordinate::stringFromColumnIndex($c);
        $v = $tarif->getCell($letter.$r)->getValue();
        if ($v !== null && $v !== '') {
            $cells[] = $letter.'='.(is_string($v) ? $v : (string) $v);
        }
    }
    echo "R{$r}: ".implode(' | ', $cells)."\n";
}
