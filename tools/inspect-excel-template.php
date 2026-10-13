<?php

require __DIR__.'/../vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php tools/inspect-excel-template.php <file.xlsx>\n");
    exit(1);
}

$ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
foreach ($ss->getAllSheets() as $sheet) {
    echo 'Sheet: '.$sheet->getTitle().PHP_EOL;
    for ($r = 1; $r <= 20; $r++) {
        $row = [];
        foreach (range('A', 'Q') as $c) {
            $v = $sheet->getCell($c.$r)->getValue();
            if ($v !== null && $v !== '') {
                $row[] = $c.$r.'='.str_replace(["\r", "\n"], ' ', (string) $v);
            }
        }
        if ($row !== []) {
            echo 'R'.$r.': '.implode(' | ', $row).PHP_EOL;
        }
    }
    echo 'Merges: '.implode(', ', $sheet->getMergeCells()).PHP_EOL;
    echo 'Row heights: ';
    for ($r = 1; $r <= 15; $r++) {
        $h = $sheet->getRowDimension($r)->getRowHeight();
        if ($h > 0) {
            echo "R{$r}={$h} ";
        }
    }
    echo PHP_EOL;
    echo '--- Formatted values R1-R7 ---'.PHP_EOL;
    foreach (range(1, 7) as $r) {
        $cells = [];
        foreach (range('A', 'R') as $c) {
            $v = $sheet->getCell($c.$r)->getFormattedValue();
            if ($v !== null && $v !== '') {
                $cells[] = $c.'='.$v;
            }
        }
        if ($cells !== []) {
            echo 'R'.$r.': '.implode(' | ', $cells).PHP_EOL;
        }
    }
}
