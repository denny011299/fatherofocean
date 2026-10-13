<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:/Users/denny/Downloads/contoh layout export excel (1).xlsx';
if (! is_file($path)) {
    fwrite(STDERR, "Missing: {$path}\n");
    exit(1);
}

$book = IOFactory::load($path);
$sheet = $book->getSheetByName('Daftar Kirim') ?? $book->getActiveSheet();
echo 'Sheet: '.$sheet->getTitle().PHP_EOL.PHP_EOL;

for ($row = 1; $row <= 8; $row++) {
    $cells = [];
    foreach (range('A', 'R') as $col) {
        $value = $sheet->getCell($col.$row)->getValue();
        if ($value !== null && $value !== '') {
            $cells[$col] = $value;
        }
    }
    if ($cells !== []) {
        echo "R{$row}: ".json_encode($cells, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

echo PHP_EOL.'Merges:'.PHP_EOL;
foreach ($sheet->getMergeCells() as $merge) {
    echo $merge.PHP_EOL;
}
