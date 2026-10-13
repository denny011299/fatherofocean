<?php

declare(strict_types=1);

$path = 'C:/Users/denny/Downloads/ocew9596_ocean.sql';
$fh = fopen($path, 'r');
if ($fh === false) {
    fwrite(STDERR, "cannot open\n");
    exit(1);
}

$needles = ['16.04.10', '0905.01', '1605.08', '2105.16', 'ARIASBY', 'GAXYB74'];
$invoiceItemHits = 0;
$barangHits = [];

while (($line = fgets($fh)) !== false) {
    $isBarangInsert = str_contains($line, 'INSERT INTO `barangs`') || (str_contains($line, ',\'EK/ON/ARIASBY\'') || str_contains($line, ',\'EK/ON/GAXYB74\''));
    $isInvoiceItem = str_contains($line, 'INSERT INTO `invoice_items`') || str_contains($line, 'invoice_items');

    foreach ($needles as $needle) {
        if (! str_contains($line, $needle)) {
            continue;
        }
        if (str_contains($line, 'activity_logs')) {
            continue;
        }
        if (str_contains($line, 'INSERT INTO `barangs`') || preg_match("/\([0-9]+,'".preg_quote($needle, '/')."'/", $line) || str_contains($line, "'{$needle}'")) {
            if (str_contains($line, '`barangs`') || preg_match("/^\([0-9]+,'/", trim($line)) || str_contains($line, "INSERT INTO `barangs`")) {
                // extract snippets around needle
            }
            $pos = strpos($line, $needle);
            $start = max(0, $pos - 80);
            $snippet = substr($line, $start, 220);
            $barangHits[] = $snippet;
        }
    }

    if (str_contains($line, 'INSERT INTO `invoice_items`')) {
        if (str_contains($line, ',109,') || str_contains($line, ',121,') || str_contains($line, ',131,') || str_contains($line, ',114,')) {
            $invoiceItemHits++;
            echo "invoice_items hit: ".substr($line, 0, 200)."\n";
        }
    }
}
fclose($fh);

echo "barang-ish snippets:\n";
foreach (array_slice(array_unique($barangHits), 0, 40) as $h) {
    echo $h."\n---\n";
}
echo "invoice_item_hits={$invoiceItemHits}\n";
