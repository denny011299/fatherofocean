<?php

declare(strict_types=1);

$path = 'C:/Users/denny/Downloads/ocew9596_ocean.sql';
$fh = fopen($path, 'r');
if ($fh === false) {
    exit(1);
}

$ids = [109, 121, 122, 124, 125, 126, 129, 131, 133, 114, 148, 168, 196, 225];
$idSet = array_fill_keys($ids, true);

$inBarangs = false;
$inInvoiceItems = false;
$foundBarangs = [];
$foundInvoiceItems = [];

while (($line = fgets($fh)) !== false) {
    if (str_contains($line, 'INSERT INTO `barangs`')) {
        $inBarangs = true;
        $inInvoiceItems = false;
    } elseif (str_contains($line, 'INSERT INTO `invoice_items`')) {
        $inInvoiceItems = true;
        $inBarangs = false;
    } elseif (str_starts_with(ltrim($line), 'INSERT INTO')) {
        $inBarangs = false;
        $inInvoiceItems = false;
    }

    if ($inBarangs) {
        foreach ($ids as $id) {
            if (preg_match('/\('.$id.',/', $line)) {
                // crude parse: id, no_index early fields
                if (preg_match('/\('.$id.',\'([^\']*)\',\'([^\']*)\'/', $line, $m)) {
                    $foundBarangs[$id] = [
                        'no_index' => $m[1],
                        'snippet' => substr($line, max(0, strpos($line, '('.$id.',') - 0), 350),
                    ];
                } else {
                    $foundBarangs[$id] = ['snippet' => substr($line, 0, 350)];
                }
            }
        }
        if (str_contains($line, 'ARIASBY') || str_contains($line, 'GAXYB74')) {
            // capture price-like numbers near end - look for status
            if (preg_match("/\(([0-9]+),'([^']*)','([^']*)'/", $line, $m) && (str_contains($line, 'ARIASBY') || str_contains($line, 'GAXYB74'))) {
                // continue scanning same insert tuples - split by ),(
            }
            foreach (explode('),(', $line) as $tuple) {
                if (! str_contains($tuple, 'ARIASBY') && ! str_contains($tuple, 'GAXYB74')) {
                    continue;
                }
                $tuple = trim($tuple, "();\n\r ");
                // get id at start
                if (! preg_match('/^([0-9]+),/', $tuple, $idm) && ! preg_match('/\(([0-9]+),/', '('.$tuple, $idm)) {
                    if (preg_match('/([0-9]+),\'/', $tuple, $idm2)) {
                        $bid = (int) $idm2[1];
                    } else {
                        continue;
                    }
                } else {
                    $bid = (int) $idm[1];
                }
                // status usually near end as 'bongkar' or 'stuffing'
                preg_match_all("/'(stuffing|bongkar|eta|etd|inbound|ditarik_kembali)'/", $tuple, $sm);
                preg_match("/'EK\\/ON\\/[A-Z0-9]+'/", $tuple, $mm);
                // price_per_cbm often decimal
                preg_match_all('/,([0-9]+\\.[0-9]+),/', $tuple, $decimals);
                echo "BARANG tuple id~ marking=".($mm[0] ?? '?')." statuses=".json_encode($sm[1] ?? [])." decimals_sample=".json_encode(array_slice($decimals[1] ?? [], 0, 8))."\n";
                echo "  head=".substr($tuple, 0, 180)."\n";
            }
        }
    }

    if ($inInvoiceItems) {
        foreach ($ids as $id) {
            // invoice_items: (id, invoice_id, barang_id, ...
            if (preg_match('/,\s*'.$id.'\s*,/', $line) || preg_match('/\([0-9]+,[0-9]+,'.$id.',/', $line)) {
                if (preg_match_all('/\(([0-9]+),([0-9]+),('.$id.'),/', $line, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $hit) {
                        $foundInvoiceItems[] = ['item_id' => $hit[1], 'invoice_id' => $hit[2], 'barang_id' => $hit[3]];
                    }
                }
            }
        }
    }
}
fclose($fh);

echo "\nInvoice items for target barang ids:\n";
echo json_encode($foundInvoiceItems, JSON_PRETTY_PRINT)."\n";
echo 'count='.count($foundInvoiceItems)."\n";
