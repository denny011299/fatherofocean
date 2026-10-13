<?php

require __DIR__ . '/../../vendor/autoload.php';

$path = 'c:/Users/denny/Downloads/PL INV TJ260428.xls';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
$ss = $reader->load($path);

$ss0 = $ss->getSheet(0);
foreach ($ss0->getDrawingCollection() as $idx => $d) {
    echo "Sheet0 drawing #{$idx}: " . $d->getCoordinates() . ' ' . $d->getName() . "\n";
}

// yellow fills sample
for ($r = 1; $r <= 15; $r++) {
    foreach (range('A', 'L') as $c) {
        $fill = $ss0->getStyle($c . $r)->getFill()->getStartColor()->getRGB();
        if ($fill && $fill !== 'FFFFFF' && $fill !== '000000') {
            echo "Fill {$c}{$r}: {$fill}\n";
        }
    }
}

foreach ($ss->getSheetNames() as $i => $name) {
    echo "Sheet {$i}: {$name}\n";
    $ws = $ss->getSheet($i);
    echo 'Highest row: ' . $ws->getHighestRow() . ', col: ' . $ws->getHighestColumn() . "\n";

    foreach ($ws->getDrawingCollection() as $d) {
        echo 'Drawing at: ' . $d->getCoordinates() . ' name=' . $d->getName() . "\n";
    }

    $mergeCells = $ws->getMergeCells();
    echo 'Merge count: ' . count($mergeCells) . "\n";
    foreach (array_slice($mergeCells, 0, 30) as $m) {
        echo '  merge: ' . $m . "\n";
    }

    for ($r = 100; $r <= min(111, (int) $ws->getHighestRow()); $r++) {
        $cells = [];
        foreach (range('A', 'M') as $c) {
            $v = $ws->getCell($c . $r)->getValue();
            if ($v !== null && $v !== '') {
                $cells[] = $c . $r . ':' . substr(str_replace(["\n", "\r"], ' ', (string) $v), 0, 50);
            }
        }
        if ($cells) {
            echo "R{$r}: " . implode(' | ', $cells) . "\n";
        }
    }

    for ($r = 1; $r <= min(40, (int) $ws->getHighestRow()); $r++) {
        $cells = [];
        foreach (range('A', 'Q') as $c) {
            $v = $ws->getCell($c . $r)->getValue();
            if ($v !== null && $v !== '') {
                $s = str_replace(["\n", "\r"], ' ', (string) $v);
                $cells[] = $c . $r . ':' . substr($s, 0, 70);
            }
        }
        if ($cells) {
            echo "R{$r}: " . implode(' | ', $cells) . "\n";
        }
    }
    echo "---\n";
}
