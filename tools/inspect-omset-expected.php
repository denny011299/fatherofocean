<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$path = $argv[1] ?? 'C:/Users/denny/Downloads/Rekapan omset kontainer 3.xlsx';
if (! is_file($path)) {
    fwrite(STDERR, "Missing: {$path}\n");
    exit(1);
}

$ss = IOFactory::load($path);
foreach ($ss->getAllSheets() as $sheet) {
    echo '=== SHEET: '.$sheet->getTitle()." ===\n";
    $highestRow = (int) $sheet->getHighestRow();
    $highestCol = $sheet->getHighestColumn();
    $highestColIndex = Coordinate::columnIndexFromString($highestCol);
    echo "rows={$highestRow} cols={$highestCol} ({$highestColIndex})\n";

    echo "-- Merges --\n";
    foreach ($sheet->getMergeCells() as $merge) {
        echo $merge."\n";
    }

    echo "-- Row heights / widths (sample) --\n";
    for ($c = 1; $c <= min($highestColIndex, 35); $c++) {
        $letter = Coordinate::stringFromColumnIndex($c);
        echo $letter.'='.$sheet->getColumnDimension($letter)->getWidth()."\n";
    }

    for ($r = 1; $r <= min(8, $highestRow); $r++) {
        $cells = [];
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $cell = $sheet->getCell($letter.$r);
            $v = $cell->getValue();
            if ($v === null || $v === '') {
                continue;
            }
            $fill = $cell->getStyle()->getFill();
            $fillType = $fill->getFillType();
            $color = '';
            if ($fillType && $fillType !== Fill::FILL_NONE) {
                $rgb = $fill->getStartColor()->getRGB();
                $color = " fill={$rgb}";
            }
            $font = $cell->getStyle()->getFont();
            $bold = $font->getBold() ? ' bold' : '';
            $fontColor = $font->getColor()->getRGB();
            $fontColorPart = $fontColor ? " font={$fontColor}" : '';
            $text = is_string($v) ? str_replace(["\r", "\n"], ' ', $v) : (string) $v;
            $cells[] = "{$letter}={$text}{$color}{$bold}{$fontColorPart}";
        }
        echo 'R'.$r.': '.implode(' | ', $cells)."\n";
    }

    echo "-- Formulas sample (rows 2-25) --\n";
    for ($r = 2; $r <= min(25, $highestRow); $r++) {
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $cell = $sheet->getCell($letter.$r);
            $v = $cell->getValue();
            if (is_string($v) && str_starts_with($v, '=')) {
                $fill = $cell->getStyle()->getFill()->getStartColor()->getRGB();
                $fillPart = $fill ? " fill={$fill}" : '';
                echo "{$letter}{$r}={$v}{$fillPart}\n";
            }
        }
    }

    echo "-- Yellow / highlighted cells --\n";
    for ($r = 1; $r <= $highestRow; $r++) {
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $cell = $sheet->getCell($letter.$r);
            $fill = $cell->getStyle()->getFill();
            if (! $fill->getFillType() || $fill->getFillType() === Fill::FILL_NONE) {
                continue;
            }
            $rgb = strtoupper((string) $fill->getStartColor()->getRGB());
            if ($rgb === '' || $rgb === 'FFFFFF' || $rgb === '000000') {
                continue;
            }
            // focus yellow-ish
            if (! preg_match('/^F{2}/', $rgb) && ! str_contains($rgb, 'FFCC') && ! str_contains($rgb, 'FFFF00') && ! str_contains($rgb, 'FFC') && ! str_contains($rgb, 'FFD')) {
                if (! in_array($rgb, ['FFFF00', 'FFCC00', 'FFF2CC', 'FFFF99', 'FFEB9C', 'FCE4D6', 'FFF2CC'], true)) {
                    continue;
                }
            }
            $v = $cell->getValue();
            $text = is_string($v) ? str_replace(["\r", "\n"], ' ', $v) : (string) ($v ?? '');
            echo "{$letter}{$r} fill={$rgb} val={$text}\n";
        }
    }

    echo "-- All non-white fills (unique by row) --\n";
    $seenRows = [];
    for ($r = 1; $r <= $highestRow; $r++) {
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $cell = $sheet->getCell($letter.$r);
            $fill = $cell->getStyle()->getFill();
            if (! $fill->getFillType() || $fill->getFillType() === Fill::FILL_NONE) {
                continue;
            }
            $rgb = strtoupper((string) $fill->getStartColor()->getRGB());
            if ($rgb === '' || $rgb === 'FFFFFF') {
                continue;
            }
            if (isset($seenRows[$r.'|'.$rgb])) {
                continue;
            }
            $seenRows[$r.'|'.$rgb] = true;
            $aVal = $sheet->getCell('A'.$r)->getValue();
            $bVal = $sheet->getCell('B'.$r)->getValue();
            $dVal = $sheet->getCell('D'.$r)->getValue();
            echo "row={$r} fill={$rgb} A=".($aVal ?? '').' B='.($bVal ?? '').' D='.($dVal ?? '')."\n";
        }
    }
}
