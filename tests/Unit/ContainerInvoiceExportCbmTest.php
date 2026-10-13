<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\ContainerInvoiceExport;
use App\Models\Barang;
use App\Models\BarangDimension;
use App\Models\BarangAdditionalPrice;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Support\CbmFormat;
use App\Support\ExcelDecimalFormat;
use App\Support\InvoicePdfData;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;
use Tests\TestCase;

final class ContainerInvoiceExportCbmTest extends TestCase
{
    public function test_barang_display_volume_prefers_dimensions_sum(): void
    {
        $barang = new Barang(['total_cbm' => 9.999]);
        $barang->setRelation('dimensions', new Collection([
            new BarangDimension(['volume_cbm' => 1.2344]),
            new BarangDimension(['volume_cbm' => 2.3456]),
        ]));

        $this->assertSame(3.58, InvoicePdfData::barangDisplayVolume($barang));
    }

    public function test_dimension_line_final_cbm_uses_truncate_rule(): void
    {
        $barang = new Barang(['total_cbm' => 7.08599]);
        $barang->setRelation('products', new Collection());
        $barang->setRelation('dimensions', new Collection([
            new BarangDimension([
                'panjang' => 100,
                'lebar' => 100,
                'tinggi' => 100,
                'ctn_count' => 7,
            ]),
        ]));

        $lines = $this->dimensionLinesForBarang($barang);

        $this->assertSame(CbmFormat::truncate(7.0), $lines[0]['final_cbm']);
        $this->assertSame(7.0, $lines[0]['total_cbm']);
        $this->assertSame(100.0, $lines[0]['length']);
        $this->assertSame(100.0, $lines[0]['width']);
        $this->assertSame(100.0, $lines[0]['height']);
    }

    public function test_product_dimension_lines_match_web_packing_cbm(): void
    {
        $barang = new Barang(['total_cbm' => 5.803, 'ctns' => 3]);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 3,
                'length_cm' => 110,
                'width_cm' => 88,
                'height_cm' => 200,
                'gross_weight_kg' => 12.5,
                'volume_cbm' => 5.803,
            ]),
        ]));

        $lines = $this->dimensionLinesForBarang($barang);

        $this->assertCount(1, $lines);
        $this->assertSame(3, $lines[0]['coly']);
        $this->assertSame(110.0, $lines[0]['length']);
        $this->assertSame(88.0, $lines[0]['width']);
        $this->assertSame(200.0, $lines[0]['height']);
        $this->assertSame(5.803, $lines[0]['final_cbm']);
        $this->assertEqualsWithDelta(5.808, $lines[0]['total_cbm'], 0.001);
        $this->assertSame(InvoicePdfData::barangDisplayVolume($barang), 5.803);
    }

    public function test_product_lines_preferred_over_legacy_dimensions(): void
    {
        $barang = new Barang(['total_cbm' => 2.0, 'ctns' => 1]);
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 1,
                'length_cm' => 100,
                'width_cm' => 100,
                'height_cm' => 100,
                'volume_cbm' => 1.0,
            ]),
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 2,
                'ctns' => 1,
                'length_cm' => 100,
                'width_cm' => 100,
                'height_cm' => 100,
                'volume_cbm' => 1.0,
            ]),
        ]));
        $barang->setRelation('dimensions', new Collection([
            new BarangDimension([
                'panjang' => 10,
                'lebar' => 10,
                'tinggi' => 10,
                'ctn_count' => 1,
                'volume_cbm' => 0.001,
            ]),
        ]));

        $lines = $this->dimensionLinesForBarang($barang);

        $this->assertCount(2, $lines);
        $this->assertSame(100.0, $lines[0]['length']);
        $this->assertSame(1.0, $lines[0]['final_cbm']);
    }

    public function test_container_invoice_export_sheet_writes_product_dimensions(): void
    {
        $container = new Container(['partai' => 'P-CBM']);
        $barang = new Barang([
            'id' => 1,
            'no_index' => '1001',
            'marking' => null,
            'total_cbm' => 5.803,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 0,
            'ctns' => 3,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 3,
                'length_cm' => 110,
                'width_cm' => 88,
                'height_cm' => 200,
                'volume_cbm' => 5.803,
            ]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(110.0, (float) $sheet->getCell('E3')->getValue());
        $this->assertSame(88.0, (float) $sheet->getCell('F3')->getValue());
        $this->assertSame(200.0, (float) $sheet->getCell('G3')->getValue());
        $this->assertSame(5.803, (float) $sheet->getCell('H3')->getValue());
    }

    public function test_container_invoice_export_sheet_has_grouped_headers_and_final_cbm(): void
    {
        $container = new Container(['partai' => 'P-CBM']);
        $barang = new Barang([
            'id' => 1,
            'no_index' => '1001',
            'marking' => null,
            'total_cbm' => 7.08599,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 0,
            'ctns' => 2,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame('CBM', $sheet->getCell('E1')->getValue());
        $this->assertSame('Final', $sheet->getCell('H2')->getValue());
        $this->assertSame('Berat', $sheet->getCell('I1')->getValue());
        $this->assertSame('Berat/coly', $sheet->getCell('I2')->getValue());
        $this->assertSame('Harga', $sheet->getCell('K1')->getValue());
        $this->assertSame('cbm/cbm', $sheet->getCell('K2')->getValue());
        $this->assertSame('KG/KG', $sheet->getCell('L2')->getValue());
        $this->assertSame('Extra', $sheet->getCell('M2')->getValue());
        $this->assertSame('note', $sheet->getCell('N2')->getValue());
        $this->assertSame('Total harga', $sheet->getCell('O1')->getValue());
        $this->assertSame('Marketing', $sheet->getCell('P1')->getValue());
        $this->assertSame(7.086, (float) $sheet->getCell('H3')->getValue());
    }

    public function test_container_invoice_export_applies_whole_rupiah_and_decimal_formats(): void
    {
        $container = new Container(['partai' => 'P-FMT']);
        $barang = new Barang([
            'id' => 1,
            'no_index' => '1001',
            'marking' => null,
            'total_cbm' => 5.803,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 0,
            'ctns' => 3,
            'total_gross_weight' => 15,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 3,
                'length_cm' => 15,
                'width_cm' => 10,
                'height_cm' => 10,
                'gross_weight_kg' => 15,
                'volume_cbm' => 0.001,
            ]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(ExcelDecimalFormat::RUPIAH_WHOLE, $sheet->getStyle('O3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('I3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('J3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::DECIMAL_3, $sheet->getStyle('H3')->getNumberFormat()->getFormatCode());
        $this->assertSame(5_803_000, (int) $sheet->getCell('O3')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame(15, (int) $sheet->getCell('I3')->getValue());
        $this->assertSame(45, (int) $sheet->getCell('J3')->getValue());
        $this->assertSame('CBM', $sheet->getCell('N3')->getValue());
        $this->assertStringStartsWith('0', (string) $sheet->getCell('H3')->getFormattedValue());
    }

    public function test_container_invoice_export_applies_weight_and_cbm_number_formats(): void
    {
        $container = new Container(['partai' => 'P-FMT']);
        $barang = new Barang([
            'id' => 1,
            'no_index' => '1001',
            'marking' => null,
            'total_cbm' => 5.803,
            'total_gross_weight' => 45.0,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 0,
            'ctns' => 3,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 3,
                'length_cm' => 110,
                'width_cm' => 88,
                'height_cm' => 200,
                'gross_weight_kg' => 45.0,
                'volume_cbm' => 5.803,
            ]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('E3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::DECIMAL_3, $sheet->getStyle('H3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('I3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('J3')->getNumberFormat()->getFormatCode());
        $this->assertSame(45, (int) $sheet->getCell('I3')->getValue());
        $this->assertSame(135, (int) $sheet->getCell('J3')->getValue());
    }

    public function test_container_invoice_export_applies_rupiah_whole_format_to_price_columns(): void
    {
        $container = new Container(['partai' => 'P-CBM']);
        $barang = new Barang([
            'id' => 1,
            'no_index' => '1001',
            'marking' => null,
            'total_cbm' => 5.275,
            'price_per_cbm' => 5_200_000,
            'extra_charge' => 0,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        foreach (['K', 'L', 'M', 'O'] as $column) {
            $this->assertSame(
                ExcelDecimalFormat::RUPIAH_WHOLE,
                $sheet->getStyle("{$column}3")->getNumberFormat()->getFormatCode(),
            );
        }

        $this->assertSame(5_200_000, (int) $sheet->getCell('K3')->getValue());
        $this->assertNull($sheet->getCell('L3')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('CBM', $sheet->getCell('N3')->getValue());
        $this->assertSame(27_430_000, (int) $sheet->getCell('O3')->getValue());
    }

    public function test_container_invoice_export_shows_split_harga_and_overweight_keterangan(): void
    {
        $container = new Container(['partai' => 'P-OW']);
        $barang = new Barang([
            'id' => 2,
            'no_index' => '1002',
            'marking' => null,
            'total_cbm' => 1.0,
            'total_gross_weight' => 600,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 1_000_000,
            'weight_price' => 15_000,
            'extra_charge' => 0,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(1_000_000, (int) $sheet->getCell('K3')->getValue());
        $this->assertSame(15_000, (int) $sheet->getCell('L3')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('(Overweight - 100)', $sheet->getCell('N3')->getValue());
    }

    public function test_container_invoice_export_shows_kg_price_only_for_weight_pricing(): void
    {
        $container = new Container(['partai' => 'P-KG']);
        $barang = new Barang([
            'id' => 3,
            'no_index' => '1003',
            'marking' => null,
            'total_cbm' => 0.5,
            'total_gross_weight' => 800,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 0,
            'weight_price' => 12_000,
            'extra_charge' => 0,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertNull($sheet->getCell('K3')->getValue());
        $this->assertSame(12_000, (int) $sheet->getCell('L3')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('KG', $sheet->getCell('N3')->getValue());
    }

    public function test_container_invoice_export_writes_whole_kg_for_weight_columns(): void
    {
        $container = new Container(['partai' => 'P-WGT']);
        $barang = new Barang([
            'id' => 5,
            'no_index' => '1005',
            'marking' => null,
            'total_cbm' => 1.0,
            'total_gross_weight' => 35.4,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 0,
            'ctns' => 5,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'ctns' => 5,
                'length_cm' => 100,
                'width_cm' => 100,
                'height_cm' => 100,
                'gross_weight_kg' => 35.4,
                'volume_cbm' => 1.0,
            ]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('I3')->getNumberFormat()->getFormatCode());
        $this->assertSame(ExcelDecimalFormat::COUNT, $sheet->getStyle('J3')->getNumberFormat()->getFormatCode());
        $this->assertSame(35, (int) $sheet->getCell('I3')->getValue());
        $this->assertSame(177, (int) $sheet->getCell('J3')->getValue());
        $this->assertSame('35', $sheet->getCell('I3')->getFormattedValue());
        $this->assertSame('177', $sheet->getCell('J3')->getFormattedValue());
    }

    public function test_container_invoice_export_shows_extra_charge_in_harga_extra_column(): void
    {
        $container = new Container(['partai' => 'P-EXTRA']);
        $barang = new Barang([
            'id' => 4,
            'no_index' => '1004',
            'marking' => null,
            'total_cbm' => 1.0,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 50_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $barang->setRelation('additionalPrices', new Collection([
            new BarangAdditionalPrice(['name' => 'Kayu', 'price' => 75_000, 'sort_order' => 0]),
            new BarangAdditionalPrice(['name' => 'Packing', 'price' => 25_000, 'sort_order' => 1]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(150_000, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('CBM, Kayu, Packing', $sheet->getCell('N3')->getValue());
        $this->assertSame(1_150_000, (int) $sheet->getCell('O3')->getValue());
    }

    public function test_container_invoice_export_note_combines_pricing_keterangan_and_custom_keterangan(): void
    {
        $container = new Container(['partai' => 'P-NOTE']);
        $barang = new Barang([
            'id' => 6,
            'no_index' => '1006',
            'marking' => null,
            'keterangan' => 'Fragile',
            'total_cbm' => 1.0,
            'price_per_cbm' => 1_000_000,
            'extra_charge' => 30_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $barang->setRelation('additionalPrices', new Collection([
            new BarangAdditionalPrice(['name' => 'Kayu', 'price' => 30_000, 'sort_order' => 0]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(60_000, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('CBM, Kayu, Fragile', $sheet->getCell('N3')->getValue());
        $this->assertSame(1_060_000, (int) $sheet->getCell('O3')->getValue());
    }

    public function test_container_invoice_export_note_combines_overweight_keterangan_with_extras(): void
    {
        $container = new Container(['partai' => 'P-OW-EXTRA']);
        $barang = new Barang([
            'id' => 7,
            'no_index' => '1007',
            'marking' => null,
            'total_cbm' => 1.0,
            'total_gross_weight' => 600,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 1_000_000,
            'weight_price' => 15_000,
            'extra_charge' => 25_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $barang->setRelation('additionalPrices', new Collection([
            new BarangAdditionalPrice(['name' => 'Packing', 'price' => 25_000, 'sort_order' => 0]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(50_000, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame('(Overweight - 100), Packing', $sheet->getCell('N3')->getValue());
        $this->assertSame(2_550_000, (int) $sheet->getCell('O3')->getValue());
    }

    public function test_container_invoice_export_includes_withdrawal_additional_price_in_extra_and_total(): void
    {
        $container = new Container(['partai' => 'P-TARIK']);
        $barang = new Barang([
            'id' => 8,
            'no_index' => '2001',
            'marking' => null,
            'status' => Barang::STATUS_DITARIK_KEMBALI,
            'total_cbm' => 10.0,
            'price_per_cbm' => 2_885_820,
            'extra_charge' => 0,
            'ctns' => 1,
        ]);
        $barang->setRelation('customer', null);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('products', new Collection());
        $barang->setRelation('additionalPrices', new Collection([
            new BarangAdditionalPrice([
                'name' => 'biaya tarik barang gudang china (500 rmb x 2610)',
                'price' => 1_305_000,
                'sort_order' => 0,
            ]),
        ]));
        $container->setRelation('barangs', new Collection([$barang]));

        $sheet = $this->buildExportSheet($container);

        $this->assertSame(1_305_000, (int) $sheet->getCell('M3')->getValue());
        $this->assertSame(
            'CBM, biaya tarik barang gudang china (500 rmb x 2610)',
            $sheet->getCell('N3')->getValue(),
        );
        $this->assertSame(30_163_200, (int) $sheet->getCell('O3')->getValue());
    }

    public function test_mic_cable_product_line_matches_pdf_volume(): void
    {
        $barang = new Barang(['total_cbm' => 0.106, 'ctns' => 5, 'price_per_cbm' => 1_000_000]);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('additionalPrices', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'product_name' => 'MIC Cable',
                'ctns' => 5,
                'length_cm' => 31,
                'width_cm' => 31,
                'height_cm' => 22,
                'volume_cbm' => 0.106,
            ]),
        ]));

        $lines = $this->dimensionLinesForBarang($barang);
        $pdfRows = $this->pdfProductRowsForBarang($barang);

        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]['coly']);
        $this->assertSame(31.0, $lines[0]['length']);
        $this->assertSame(31.0, $lines[0]['width']);
        $this->assertSame(22.0, $lines[0]['height']);
        $this->assertEqualsWithDelta(0.10571, $lines[0]['total_cbm'], 0.00001);
        $this->assertSame(0.106, $lines[0]['final_cbm']);
        $this->assertSame('0,106', $pdfRows[0]['volume']);
        $this->assertSame($lines[0]['final_cbm'], CbmFormat::truncate(0.10571));
    }

    public function test_excel_final_cbm_matches_invoice_pdf_data_for_each_product(): void
    {
        $barang = new Barang(['total_cbm' => 2.939, 'ctns' => 8, 'price_per_cbm' => 1_000_000]);
        $barang->setRelation('dimensions', new Collection());
        $barang->setRelation('additionalPrices', new Collection());
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 1,
                'product_name' => 'Line A',
                'ctns' => 5,
                'length_cm' => 31,
                'width_cm' => 31,
                'height_cm' => 22,
                'volume_cbm' => 0.106,
            ]),
            new BarangProduct([
                'parent_id' => null,
                'sort_order' => 2,
                'product_name' => 'Line B',
                'ctns' => 3,
                'length_cm' => 110,
                'width_cm' => 88,
                'height_cm' => 200,
                'volume_cbm' => 5.803,
            ]),
        ]));

        $lines = $this->dimensionLinesForBarang($barang);
        $pdfRows = array_values(array_filter(
            $this->pdfProductRowsForBarang($barang),
            static fn (array $row): bool => ($row['volume'] ?? '-') !== '-',
        ));

        $this->assertCount(2, $lines);
        $this->assertCount(2, $pdfRows);

        foreach ($lines as $index => $line) {
            $pdfVolume = str_replace(',', '.', (string) $pdfRows[$index]['volume']);
            $this->assertSame((float) $pdfVolume, $line['final_cbm']);
        }
    }

    /**
     * @return list<array{
     *     coly: ?int,
     *     length: ?float,
     *     width: ?float,
     *     height: ?float,
     *     total_cbm: ?float,
     *     final_cbm: ?float,
     *     weight_per_coly: ?float,
     *     total_weight: ?float,
     * }>
     */
    private function dimensionLinesForBarang(Barang $barang): array
    {
        $container = new Container(['partai' => 'TEST']);
        $container->setRelation('barangs', new Collection([$barang]));

        $export = new ContainerInvoiceExport($container);
        $method = new ReflectionMethod($export, 'dimensionLinesForBarang');
        $method->setAccessible(true);

        return $method->invoke($export, $barang);
    }

    /**
     * @return list<array<string, string>>
     */
    private function pdfProductRowsForBarang(Barang $barang): array
    {
        $barang->setRelation('additionalPrices', $barang->relationLoaded('additionalPrices')
            ? $barang->additionalPrices
            : new Collection());

        $method = new ReflectionMethod(InvoicePdfData::class, 'productRowsForBarang');
        $method->setAccessible(true);

        return $method->invoke(null, $barang);
    }

    private function buildExportSheet(Container $container): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $export = new ContainerInvoiceExport($container);
        $method = new ReflectionMethod($export, 'buildSheet');
        $method->setAccessible(true);
        $method->invoke($export, $sheet);

        return $sheet;
    }
}
