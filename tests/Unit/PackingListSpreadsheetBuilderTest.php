<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Services\Exports\PackingListSpreadsheetBuilder;
use App\Support\CbmFormat;
use App\Support\CbmVolume;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

final class PackingListSpreadsheetBuilderTest extends TestCase
{
    public function test_builds_workbook_without_commercial_invoice_header_block(): void
    {
        $template = config('packing_list_export.template');
        $this->assertFileExists($template);

        $container = new Container([
            'code' => 'TJ-TEST-001',
            'route' => null,
        ]);
        $container->setRelation('barangs', collect());
        $container->setRelation('marking', null);

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);

        $invoice = $spreadsheet->getSheetByName('发票');
        $packing = $spreadsheet->getSheetByName('箱单');

        $this->assertNotNull($invoice);
        $this->assertNotNull($packing);

        $exporterName = (string) config('packing_list_export.exporter_company_name');
        $this->assertStringNotContainsString($exporterName, $this->sheetText($invoice));
        $this->assertStringNotContainsString($exporterName, $this->sheetText($packing));

        $this->assertStringNotContainsString('COMMERCIAL INVOICE', $this->sheetText($invoice));
        $this->assertStringNotContainsString('PACKING LIST', $this->sheetText($packing));
        $this->assertStringNotContainsString('Invoice NO', $this->sheetText($invoice));
        $this->assertStringNotContainsString('Invoice No', $this->sheetText($packing));

        $this->assertStringContainsString('Partai', (string) $invoice->getCell('A1')->getValue());
        $this->assertStringContainsString('Marks', (string) $invoice->getCell('B1')->getValue());
        $this->assertStringContainsString('Partai', (string) $packing->getCell('A1')->getValue());
        $this->assertStringContainsString('Marks', (string) $packing->getCell('B1')->getValue());
        $this->assertStringContainsString('Photo', (string) $invoice->getCell('M1')->getValue());

        $this->assertDataRowsHaveNoHighlightFill($invoice);
        $this->assertDataRowsHaveNoHighlightFill($packing);
    }

    public function test_invoice_photo_column_header_matches_adjacent_header_style(): void
    {
        $container = new Container(['code' => 'TJ-PL-HEADER']);
        $container->setRelation('barangs', collect());
        $container->setRelation('marking', null);

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $invoice = $spreadsheet->getSheetByName('发票');
        $this->assertNotNull($invoice);

        $this->assertContains('M1:M2', $invoice->getMergeCells());
        $this->assertPhotoHeaderCellMatchesReference($invoice, 'M1:M2', 'K1', 'K1');
        $this->assertStringContainsString('Photo', (string) $invoice->getCell('M1')->getValue());
    }

    public function test_invoice_photo_column_matches_adjacent_cell_borders(): void
    {
        $product = new BarangProduct([
            'product_name' => 'Sample product',
            'ctns' => 2,
            'pcs_per_box' => 10,
            'price_per_unit_rmb' => 12.5,
            'gross_weight_kg' => 5,
            'photo_path' => null,
        ]);

        $barang = new Barang(['marking' => 'PL-TEST']);
        $barang->setRelation('products', collect([$product]));

        $container = new Container(['code' => 'TJ-PL-BORDER']);
        $container->setRelation('barangs', collect([$barang]));
        $container->setRelation('marking', null);

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $invoice = $spreadsheet->getSheetByName('发票');
        $this->assertNotNull($invoice);

        $dataRow = 4;
        $referenceCell = 'K' . $dataRow;
        $photoCell = 'M' . $dataRow;

        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            $getter = 'get' . $side;
            $referenceStyle = $invoice->getStyle($referenceCell)->getBorders()->{$getter}();
            $photoStyle = $invoice->getStyle($photoCell)->getBorders()->{$getter}();

            $this->assertSame(
                $referenceStyle->getBorderStyle(),
                $photoStyle->getBorderStyle(),
                "Photo column {$side} border should match column J",
            );
            $this->assertNotSame(Border::BORDER_NONE, $photoStyle->getBorderStyle());
        }

        $this->assertSame(Fill::FILL_NONE, $invoice->getStyle($photoCell)->getFill()->getFillType());

        $photoAlignment = $invoice->getStyle($photoCell)->getAlignment();
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $photoAlignment->getHorizontal());
        $this->assertSame(Alignment::VERTICAL_CENTER, $photoAlignment->getVertical());
    }

    public function test_data_rows_apply_template_horizontal_and_marking_vertical_merges(): void
    {
        $sharedMarking = 'DEMO-PL-MARK';

        $barangA = new Barang(['marking' => $sharedMarking]);
        $barangA->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Product A1',
                'ctns' => 1,
                'pcs_per_box' => 10,
                'price_per_unit_rmb' => 5.0,
                'gross_weight_kg' => 10,
            ]),
            new BarangProduct([
                'product_name' => 'Product A2',
                'ctns' => 2,
                'pcs_per_box' => 5,
                'price_per_unit_rmb' => 3.0,
                'gross_weight_kg' => 8,
            ]),
        ]));

        $barangB = new Barang(['marking' => 'OTHER-MARK']);
        $barangB->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Product B1',
                'ctns' => 3,
                'pcs_per_box' => 4,
                'price_per_unit_rmb' => 2.0,
                'gross_weight_kg' => 6,
            ]),
        ]));

        $container = new Container(['code' => 'TJ-PL-MERGE', 'partai' => '1054']);
        $container->setRelation('barangs', collect([$barangA, $barangB]));
        $container->setRelation('marking', null);

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $invoice = $spreadsheet->getSheetByName('发票');
        $packing = $spreadsheet->getSheetByName('箱单');

        $this->assertNotNull($invoice);
        $this->assertNotNull($packing);

        $merges = $invoice->getMergeCells();

        $this->assertContains('A4:A6', $merges);
        $this->assertContains('B4:B5', $merges);
        $this->assertContains('C4:E4', $merges);
        $this->assertContains('F4:G4', $merges);
        $this->assertContains('H4:I4', $merges);
        $this->assertContains('K4:L4', $merges);
        $this->assertContains('C6:E6', $merges);
        $this->assertNotContains('M4:M5', $merges);

        $this->assertSame('1054', (string) $invoice->getCell('A4')->getValue());
        $this->assertSame($sharedMarking, $invoice->getCell('B4')->getValue());
        $this->assertSame('OTHER-MARK', $invoice->getCell('B6')->getValue());

        $packingMerges = $packing->getMergeCells();
        $this->assertContains('A4:A6', $packingMerges);
        $this->assertContains('B4:B5', $packingMerges);
        $this->assertContains('D4:E4', $packingMerges);
        $this->assertContains('F4:G4', $packingMerges);
        $this->assertContains('H4:I4', $packingMerges);
        $this->assertContains('J4:K4', $packingMerges);
        $this->assertContains('L4:M4', $packingMerges);

        $this->assertNoVerticalMergesOutsideGroupColumns($invoice, 4, 6);
        $this->assertNormalDataRowHeights($invoice, [4, 5, 6]);
    }

    public function test_packing_sheet_includes_total_summary_row_with_sums(): void
    {
        $productA = new BarangProduct([
            'product_name' => 'Product A',
            'ctns' => 2,
            'pcs_per_box' => 10,
            'length_cm' => 52.5,
            'width_cm' => 50,
            'height_cm' => 20,
            'price_per_unit_rmb' => 5.0,
            'gross_weight_kg' => 12.5,
        ]);
        $productB = new BarangProduct([
            'product_name' => 'Product B',
            'ctns' => 3,
            'pcs_per_box' => 4,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 6.67,
            'price_per_unit_rmb' => 2.0,
            'gross_weight_kg' => 8.0,
        ]);

        $barang = new Barang(['marking' => 'PL-TOTAL']);
        $barang->setRelation('products', collect([$productA, $productB]));

        $container = new Container(['code' => 'TJ-PL-TOTAL', 'partai' => '591']);
        $container->setRelation('barangs', collect([$barang]));

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $packing = $spreadsheet->getSheetByName('箱单');
        $invoice = $spreadsheet->getSheetByName('发票');

        $this->assertNotNull($packing);
        $this->assertNotNull($invoice);

        $totalRow = 6;

        $this->assertSame('Total', $packing->getCell("C{$totalRow}")->getValue());
        $this->assertSame('=SUM(D4:D5)', $packing->getCell("D{$totalRow}")->getValue());
        $this->assertSame('=SUM(F4:F5)', $packing->getCell("F{$totalRow}")->getValue());
        $this->assertSame('=SUM(H4:H5)', $packing->getCell("H{$totalRow}")->getValue());
        $this->assertSame('=SUM(J4:J5)', $packing->getCell("J{$totalRow}")->getValue());
        $this->assertSame('=SUM(L4:L5)', $packing->getCell("L{$totalRow}")->getValue());

        $this->assertSame(5, $packing->getCell("D{$totalRow}")->getCalculatedValue());
        $this->assertSame(32, $packing->getCell("F{$totalRow}")->getCalculatedValue());
        $this->assertSame(49.0, $packing->getCell("H{$totalRow}")->getCalculatedValue());
        $this->assertSame(44.0, $packing->getCell("J{$totalRow}")->getCalculatedValue());
        $this->assertEqualsWithDelta(
            CbmVolume::line(52.5, 50, 20, 2) + CbmVolume::line(10, 10, 6.67, 3),
            (float) $packing->getCell("L{$totalRow}")->getCalculatedValue(),
            0.001,
        );

        $this->assertContains("D{$totalRow}:E{$totalRow}", $packing->getMergeCells());
        $this->assertContains("F{$totalRow}:G{$totalRow}", $packing->getMergeCells());
        $this->assertContains("H{$totalRow}:I{$totalRow}", $packing->getMergeCells());
        $this->assertContains("J{$totalRow}:K{$totalRow}", $packing->getMergeCells());
        $this->assertContains("L{$totalRow}:M{$totalRow}", $packing->getMergeCells());
        $this->assertTrue($packing->getStyle("C{$totalRow}")->getFont()->getBold());

        $this->assertSame('Total Quantities', $invoice->getCell("C{$totalRow}")->getValue());
        $this->assertSame('=SUM(F4:F5)', $invoice->getCell("F{$totalRow}")->getValue());
        $this->assertSame('=SUM(K4:K5)', $invoice->getCell("K{$totalRow}")->getValue());
    }

    public function test_packing_sheet_writes_cbm_and_summary_totals(): void
    {
        $product = new BarangProduct([
            'parent_id' => null,
            'product_name' => 'Widget A',
            'ctns' => 3,
            'pcs_per_box' => 10,
            'length_cm' => 110,
            'width_cm' => 88,
            'height_cm' => 200,
            'gross_weight_kg' => 12.5,
            'volume_cbm' => CbmVolume::line(110, 88, 200, 3),
            'price_per_unit_rmb' => 5.0,
        ]);

        $barang = new Barang(['marking' => 'PL-CBM-TEST']);
        $barang->setRelation('products', collect([$product]));

        $container = new Container(['code' => 'TJ-PL-CBM', 'partai' => '591']);
        $container->setRelation('barangs', collect([$barang]));

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $packing = $spreadsheet->getSheetByName('箱单');
        $this->assertNotNull($packing);

        $expectedCbm = CbmVolume::line(110, 88, 200, 3);

        $this->assertEqualsWithDelta($expectedCbm, (float) $packing->getCell('L4')->getValue(), 0.001);
        $this->assertContains('L4:M4', $packing->getMergeCells());

        $this->assertSame('Total', $packing->getCell('C5')->getValue());
        $this->assertSame(3, (int) $packing->getCell('D5')->getCalculatedValue());
        $this->assertSame(30, (int) $packing->getCell('F5')->getCalculatedValue());
        $this->assertEqualsWithDelta(37.5, (float) $packing->getCell('H5')->getCalculatedValue(), 0.01);
        $this->assertEqualsWithDelta($expectedCbm, (float) $packing->getCell('L5')->getCalculatedValue(), 0.001);
    }

    public function test_sub_product_lines_do_not_duplicate_parent_gw_and_cbm(): void
    {
        $parent = new BarangProduct([
            'parent_id' => null,
            'product_name' => 'Parent box',
            'ctns' => 2,
            'pcs_per_box' => 1,
            'length_cm' => 100,
            'width_cm' => 80,
            'height_cm' => 60,
            'gross_weight_kg' => 20,
            'volume_cbm' => 0.96,
        ]);
        $parent->id = 10;

        $child = new BarangProduct([
            'parent_id' => 10,
            'product_name' => 'Sub item',
            'pcs_per_box' => 5,
            'price_per_unit_rmb' => 1.0,
        ]);
        $child->setRelation('parent', $parent);

        $childB = new BarangProduct([
            'parent_id' => 10,
            'product_name' => 'Sub item B',
            'pcs_per_box' => 3,
            'price_per_unit_rmb' => 2.0,
        ]);
        $childB->setRelation('parent', $parent);

        $barang = new Barang(['marking' => 'PL-SUB']);
        $barang->setRelation('products', collect([$parent, $child, $childB]));

        $container = new Container(['code' => 'TJ-PL-SUB']);
        $container->setRelation('barangs', collect([$barang]));

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $packing = $spreadsheet->getSheetByName('箱单');
        $this->assertNotNull($packing);

        $this->assertEqualsWithDelta(40.0, (float) $packing->getCell('H4')->getValue(), 0.01);
        $this->assertEqualsWithDelta(0.96, (float) $packing->getCell('L4')->getValue(), 0.001);
        $this->assertNull($packing->getCell('H5')->getValue());
        $this->assertNull($packing->getCell('H6')->getValue());
        $this->assertNull($packing->getCell('L5')->getValue());
        $this->assertNull($packing->getCell('L6')->getValue());
        $this->assertEqualsWithDelta(0.96, (float) $packing->getCell('L7')->getCalculatedValue(), 0.001);
    }

    public function test_invoice_unit_price_header_uses_rmb(): void
    {
        $barang = new Barang(['marking' => 'PL-RMB']);
        $barang->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Widget',
                'ctns' => 2,
                'pcs_per_box' => 10,
                'price_per_unit_rmb' => 59.0,
                'gross_weight_kg' => 10,
            ]),
        ]));

        $container = new Container(['code' => 'TJ-PL-RMB', 'partai' => '591']);
        $container->setRelation('barangs', collect([$barang]));

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $invoice = $spreadsheet->getSheetByName('发票');
        $this->assertNotNull($invoice);

        $unitPriceHeader = (string) $invoice->getCell('J1')->getValue();
        $amountHeader = (string) $invoice->getCell('K1')->getValue();

        $this->assertStringContainsString('Unit Price', $unitPriceHeader);
        $this->assertStringContainsString('RMB', $unitPriceHeader);
        $this->assertStringNotContainsString('USD', $unitPriceHeader);

        $this->assertStringContainsString('Amount', $amountHeader);
        $this->assertStringContainsString('RMB', $amountHeader);
        $this->assertStringNotContainsString('USD', $amountHeader);

        $this->assertContains('J1:J2', $invoice->getMergeCells());
        $this->assertContains('K1:K2', $invoice->getMergeCells());

        $this->assertSame(59.0, (float) $invoice->getCell('J4')->getValue());
        $this->assertSame(1180.0, $invoice->getCell('K4')->getCalculatedValue());
    }

    public function test_invoice_qty_price_columns_are_not_vertically_merged_for_single_product(): void
    {
        $barang = new Barang(['marking' => 'DEMO-PL-TEST']);
        $barang->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Widget',
                'ctns' => 240,
                'pcs_per_box' => 1,
                'price_per_unit_rmb' => 28.5,
                'gross_weight_kg' => 10,
            ]),
        ]));

        $container = new Container(['code' => 'TJ-PL-SINGLE']);
        $container->setRelation('barangs', collect([$barang]));

        $spreadsheet = (new PackingListSpreadsheetBuilder())->build($container);
        $invoice = $spreadsheet->getSheetByName('发票');
        $this->assertNotNull($invoice);

        $this->assertSame(240, $invoice->getCell('F4')->getValue());
        $this->assertSame(28.5, $invoice->getCell('J4')->getValue());
        $this->assertSame(6840.0, $invoice->getCell('K4')->getCalculatedValue());

        $this->assertContains('F4:G4', $invoice->getMergeCells());
        $this->assertNotContains('F4:F8', $invoice->getMergeCells());
        $this->assertNoVerticalMergesOutsideGroupColumns($invoice, 4, 4);
        $this->assertNormalDataRowHeights($invoice, [4]);
    }

    private function assertNoVerticalMergesOutsideGroupColumns(Worksheet $sheet, int $fromRow, int $toRow): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
                continue;
            }

            $startCol = $matches[1];
            $endCol = $matches[3];
            $startRow = (int) $matches[2];
            $endRow = (int) $matches[4];

            if ($startRow === $endRow || $endRow < $fromRow || $startRow > $toRow) {
                continue;
            }

            $this->assertContains(
                $startCol,
                ['A', 'B'],
                "Only partai (A) and marking (B) may use vertical merges in data rows; found [{$range}].",
            );
            $this->assertSame($startCol, $endCol);
        }
    }

    /**
     * @param  list<int>  $rows
     */
    private function assertNormalDataRowHeights(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $row) {
            $height = $sheet->getRowDimension($row)->getRowHeight();
            $this->assertLessThanOrEqual(
                20.0,
                $height,
                "Row {$row} should use normal height without a photo (got {$height} pt).",
            );
        }
    }

    private function assertPhotoHeaderCellMatchesReference(
        Worksheet $sheet,
        string $photoCell,
        string $borderReferenceCell,
        string $alignmentReferenceCell,
    ): void {
        $photoStyle = $sheet->getStyle($photoCell);
        $borderReferenceStyle = $sheet->getStyle($borderReferenceCell);
        $alignmentReferenceStyle = $sheet->getStyle($alignmentReferenceCell);

        $this->assertTrue($photoStyle->getFont()->getBold(), "{$photoCell} should be bold.");
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $photoStyle->getAlignment()->getHorizontal(),
            "{$photoCell} should be horizontally centered.",
        );
        $this->assertSame(
            $alignmentReferenceStyle->getAlignment()->getVertical(),
            $photoStyle->getAlignment()->getVertical(),
            "{$photoCell} vertical alignment should match {$alignmentReferenceCell}.",
        );
        $this->assertSame(
            $borderReferenceStyle->getFill()->getFillType(),
            $photoStyle->getFill()->getFillType(),
            "{$photoCell} fill should match {$borderReferenceCell}.",
        );

        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            $getter = 'get' . $side;
            $referenceBorder = $borderReferenceStyle->getBorders()->{$getter}();
            $photoBorder = $photoStyle->getBorders()->{$getter}();

            $this->assertSame(
                $referenceBorder->getBorderStyle(),
                $photoBorder->getBorderStyle(),
                "{$photoCell} {$side} border should match {$borderReferenceCell}",
            );
            if ($referenceBorder->getBorderStyle() !== Border::BORDER_NONE) {
                $this->assertNotSame(Border::BORDER_NONE, $photoBorder->getBorderStyle());
            }
        }
    }

    private function assertDataRowsHaveNoHighlightFill(Worksheet $sheet): void
    {
        for ($row = 4; $row <= 91; $row++) {
            $fill = $sheet->getStyle('A' . $row)->getFill();
            $this->assertSame(
                Fill::FILL_NONE,
                $fill->getFillType(),
                "Row {$row} on sheet [{$sheet->getTitle()}] should not have a solid highlight fill.",
            );
        }
    }

    private function sheetText(Worksheet $sheet): string
    {
        $parts = [];
        $highestRow = min((int) $sheet->getHighestRow(), 15);
        $highestColumn = $sheet->getHighestColumn();

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $value = $sheet->getCell($col . $row)->getValue();
                if ($value !== null && $value !== '') {
                    $parts[] = (string) $value;
                }
            }
        }

        return implode(' ', $parts);
    }
}
