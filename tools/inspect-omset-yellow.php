<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$path = 'C:/Users/denny/Downloads/Rekapan omset kontainer 3.xlsx';
$sheet = IOFactory::load($path)->getSheetByName('Omset');
$highestRow = (int) $sheet->getHighestRow();
$highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

echo "=== YELLOW ROWS (FFFF00) full values ===\n";
for ($r = 1; $r <= $highestRow; $r++) {
    $isYellow = false;
    for ($c = 1; $c <= $highestColIndex; $c++) {
        $letter = Coordinate::stringFromColumnIndex($c);
        $fill = $sheet->getCell($letter.$r)->getStyle()->getFill();
        if ($fill->getFillType() && $fill->getFillType() !== Fill::FILL_NONE) {
            $rgb = strtoupper((string) $fill->getStartColor()->getRGB());
            if ($rgb === 'FFFF00') {
                $isYellow = true;
                break;
            }
        }
    }
    if (! $isYellow) {
        continue;
    }
    $vals = [];
    foreach (['A','B','C','D','E','F','G','H','I','J','K','L','M','O','P','Q','U','V','W','X','Y','Z','AA','AB','AC','AG'] as $col) {
        $v = $sheet->getCell($col.$r)->getCalculatedValue();
        if ($v === null || $v === '') {
            $v = $sheet->getCell($col.$r)->getValue();
        }
        if ($v === null || $v === '') {
            continue;
        }
        $vals[] = $col.'='.(is_scalar($v) ? (string) $v : json_encode($v));
    }
    echo "R{$r}: ".implode(' | ', $vals)."\n";
}

echo "\n=== SUMMARY / TOTAL rows (labels in B or text) ===\n";
for ($r = 1; $r <= $highestRow; $r++) {
    $b = (string) ($sheet->getCell('B'.$r)->getValue() ?? '');
    $a = (string) ($sheet->getCell('A'.$r)->getValue() ?? '');
    $j = (string) ($sheet->getCell('J'.$r)->getValue() ?? '');
    $h = (string) ($sheet->getCell('H'.$r)->getValue() ?? '');
    if ($b === '' && $j === '' && $h === '' && $a === '') {
        continue;
    }
    if (preg_match('/total|pendapatan|ocean|profit|komisi|income|cbm|berat|perusahaan|pic/i', $b.$j.$h.$a)) {
        $cells = [];
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $v = $sheet->getCell($letter.$r)->getValue();
            if ($v === null || $v === '') {
                continue;
            }
            $cells[] = $letter.'='.(is_string($v) ? $v : (string) $v);
        }
        echo "R{$r}: ".implode(' | ', $cells)."\n";
    }
}

echo "\n=== Sheet list ===\n";
foreach (IOFactory::load($path)->getAllSheets() as $s) {
    echo $s->getTitle().' rows='.$s->getHighestRow()."\n";
}
