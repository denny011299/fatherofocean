<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$sheet = IOFactory::load('C:/Users/denny/Downloads/Rekapan omset kontainer 3.xlsx')->getSheetByName('Omset');
echo "Sample harga jual O and modal L from expected:\n";
for ($r = 2; $r <= 20; $r++) {
    $d = $sheet->getCell('D'.$r)->getValue();
    $f = $sheet->getCell('F'.$r)->getValue();
    $l = $sheet->getCell('L'.$r)->getValue();
    $o = $sheet->getCell('O'.$r)->getValue();
    $p = $sheet->getCell('P'.$r)->getValue();
    $m = $sheet->getCell('M'.$r)->getValue();
    if ($d || $o || $l) {
        echo "R{$r} D=".($d ?? '')." F=".($f ?? '')." L(modal)=".($l ?? '-')." M=".($m ?? '-')." O(jual)=".($o ?? '-')." P=".($p ?? '-')."\n";
    }
}
