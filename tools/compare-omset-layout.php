<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\Services\Exports\OmsetSpreadsheetBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

$expected = IOFactory::load('C:/Users/denny/Downloads/Rekapan omset kontainer 3.xlsx')->getSheetByName('Omset');

$sheet = (new Spreadsheet())->getActiveSheet();
(new OmsetSpreadsheetBuilder())->build($sheet, [
    'groups' => [[
        'marketing_name' => 'Test PIC',
        'sales_id' => 1,
        'commission_rate' => 2.5,
        'rows' => [[
            'serial_no' => '1205.01',
            'tanggal_masuk' => '12 May 2026',
            'marking' => 'EK/ON/ADR/MLG',
            'item' => 'Ice Bucket',
            'kategori' => 'umum',
            'cbm' => 0.1,
            'weight_threshold_kg' => 500,
            'kg' => 9.8,
            'modal_cbm_price' => 5000000,
            'modal_kg_price' => 10000,
            'sell_cbm_price' => 6500000,
            'sell_kg_price' => 12000,
            'additional_fee' => 0,
            'additional_fee_note' => '',
            'commission_rate' => 2.5,
            'marketing_staff' => 'ALWIN',
            'payment_status' => 'belum lunas',
            'status_update' => 'Bongkaran',
            'tanggal_masuk_excel' => 46154,
            'partai' => '1054',
            'company_loyalty_amount' => 150000,
            'catatan' => '',
        ], [
            'serial_no' => '1105.02',
            'tanggal_masuk' => '11 May 2026',
            'marking' => 'EK/ON/ADR/MLG',
            'item' => 'Hair',
            'kategori' => 'umum',
            'cbm' => 0.2,
            'weight_threshold_kg' => 500,
            'kg' => 5,
            'modal_cbm_price' => 5000000,
            'modal_kg_price' => 10000,
            'sell_cbm_price' => 6500000,
            'sell_kg_price' => 12000,
            'additional_fee' => 0,
            'additional_fee_note' => '',
            'commission_rate' => 2.5,
            'marketing_staff' => 'ALWIN',
            'payment_status' => 'belum lunas',
            'status_update' => 'Bongkaran',
            'tanggal_masuk_excel' => 46153,
            'partai' => '1054',
            'company_loyalty_amount' => 150000,
            'catatan' => '',
        ]],
        'ocean_link_rows' => [],
        'company_category_breakdown' => [],
    ]],
    'partai_label' => '1054',
    'company_fixed_amount' => 150000,
    'company_name' => 'Oceanlink',
]);

$cols = array_merge(range('A', 'Z'), ['AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI']);

echo "=== HEADER DIFF ===\n";
$mismatch = 0;
foreach ($cols as $c) {
    $e = (string) ($expected->getCell($c.'1')->getValue() ?? '');
    $a = (string) ($sheet->getCell($c.'1')->getValue() ?? '');
    if ($e === '' && $a === '') {
        continue;
    }
    $ok = $e === $a ? 'OK' : 'DIFF';
    if ($e !== $a) {
        $mismatch++;
    }
    echo "{$c}: expected=[{$e}] actual=[{$a}] {$ok}\n";
}
echo "header_mismatches={$mismatch}\n";

echo "\n=== FORMULA / VALUE STRUCTURE R2 ===\n";
$checks = [
    'N' => true,
    'S' => true,
    'T' => true,
    'AD' => true,
    'AE' => true,
    'AF' => true,
    'AH' => true,
    'AI' => true,
    'H' => false, // multi-row SUM vs single literal — fixture is multi
    'K' => false,
    'U' => false,
    'AG' => true,
];
$formulaMismatch = 0;
foreach ($checks as $c => $mustMatch) {
    $e = (string) ($expected->getCell($c.'2')->getValue() ?? '');
    $a = (string) ($sheet->getCell($c.'2')->getValue() ?? '');
    // For H/K/U on multi-row fixture, compare against expected multi-row pattern from R20
    if (in_array($c, ['H', 'K', 'U'], true)) {
        $e = (string) ($expected->getCell($c.'20')->getValue() ?? '');
        // Our fixture rows 2-3 same marking → SUM like expected R20
        $ok = match ($c) {
            'H' => $a === '=SUM(G2:G3)',
            'K' => $a === '=SUM(J2:J3)',
            'U' => $a === '=SUM(T2:T3)',
            default => false,
        };
        echo "{$c} multi-row actual={$a} pattern_ok=".($ok ? 'YES' : 'NO')." (expected sample e.g. {$e})\n";
        if (! $ok) {
            $formulaMismatch++;
        }
        continue;
    }
    $same = $e === $a;
    if (! $same) {
        $formulaMismatch++;
    }
    echo "{$c}2 expected={$e}\n{$c}2 actual  ={$a}\n  ".($same ? "SAME\n" : "DIFF\n");
}

echo "\nformula_structure_mismatches={$formulaMismatch}\n";

echo "\n=== AG type ===\n";
echo 'AG2='.var_export($sheet->getCell('AG2')->getValue(), true)."\n";
echo 'AG2 expected='.var_export($expected->getCell('AG2')->getValue(), true)."\n";

echo "\n=== Company money format AC2 ===\n";
echo 'actual='.$sheet->getStyle('AC2')->getNumberFormat()->getFormatCode()."\n";
echo 'expected='.$expected->getStyle('AC2')->getNumberFormat()->getFormatCode()."\n";

echo "\n=== Single-row H/K check ===\n";
$single = (new Spreadsheet())->getActiveSheet();
(new OmsetSpreadsheetBuilder())->build($single, [
    'groups' => [[
        'marketing_name' => 'PIC',
        'sales_id' => 1,
        'commission_rate' => 2.5,
        'rows' => [[
            'serial_no' => '1',
            'tanggal_masuk' => '1 May 2026',
            'marking' => 'ONLY',
            'item' => 'X',
            'kategori' => 'umum',
            'cbm' => 0.1,
            'weight_threshold_kg' => 500,
            'kg' => 9.8,
            'modal_cbm_price' => 5000000,
            'sell_cbm_price' => 6500000,
            'commission_rate' => 2.5,
            'marketing_staff' => 'A',
            'payment_status' => 'belum lunas',
            'status_update' => 'Bongkaran',
            'company_loyalty_amount' => 150000,
        ]],
    ]],
    'partai_label' => null,
    'company_fixed_amount' => 150000,
    'company_name' => 'Oceanlink',
]);
echo 'H2='.var_export($single->getCell('H2')->getValue(), true)." (expect literal 0.1)\n";
echo 'K2='.var_export($single->getCell('K2')->getValue(), true)." (expect literal 9.8)\n";
echo 'U2='.var_export($single->getCell('U2')->getValue(), true)."\n";
echo 'AE2='.var_export($single->getCell('AE2')->getValue(), true)."\n";
