<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangAdditionalPrice;
use App\Models\BarangProduct;
use App\Models\Marking;
use App\Support\BarangShippingPricing;
use App\Support\TerimaBarangPdfData;
use App\Support\TerimaBarangPdfFonts;
use App\Support\TerimaBarangPdfImages;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class TerimaBarangPdfDataTest extends TestCase
{
    public function test_format_items_list_plain_joins_all_products(): void
    {
        $products = new Collection([
            new BarangProduct(['product_name' => 'Diving sets']),
            new BarangProduct(['product_name' => 'Diving mask + accessories']),
        ]);

        $plain = TerimaBarangPdfData::formatItemsListPlain($products);

        $this->assertStringContainsString('Diving sets, Diving mask + accessories', $plain);
    }

    public function test_format_rp_prefix(): void
    {
        $this->assertSame('Rp 27,500,000', TerimaBarangPdfData::formatRp(27_500_000));
    }

    public function test_resolve_notes_display_cubic_for_numeric_type(): void
    {
        $barang = new Barang([
            'notes' => null,
            'cbm_type' => '50',
            'weight_threshold_kg' => 50,
        ]);

        $this->assertSame('Cubic', TerimaBarangPdfData::resolveNotesDisplay($barang));
    }

    public function test_resolve_pdf_font_config_when_cjk_font_present(): void
    {
        if (TerimaBarangPdfFonts::absolutePath() === null) {
            $this->markTestSkipped('CJK font file not installed.');
        }

        $config = TerimaBarangPdfData::resolvePdfFontConfig();

        $this->assertTrue($config['hasCjkFont']);
        $this->assertSame('DejaVu Sans', $config['pdfFontFamily']);
        $this->assertSame(TerimaBarangPdfFonts::FAMILY, $config['cjkFontFamily']);
    }

    public function test_cjk_html_uses_registered_font_family(): void
    {
        $html = TerimaBarangPdfData::cjkHtml(true, '(客户)');

        $this->assertStringContainsString(TerimaBarangPdfFonts::FAMILY, $html);
        $this->assertStringContainsString('客户', $html);
        $this->assertStringContainsString('cjk-block', $html);
        $this->assertStringContainsString('font-weight:normal', $html);

        $label = TerimaBarangPdfData::bilingualLabelHtml(true, 'CL', '客户');
        $this->assertStringContainsString('CL (客户)', $label);
        $this->assertStringContainsString('cjk-block', $label);
    }

    public function test_cjk_preload_includes_all_label_characters(): void
    {
        $html = TerimaBarangPdfData::cjkPreloadHtml(true);

        $this->assertStringContainsString('客户', $html);
        $this->assertStringContainsString('发票及装箱单', $html);
        $this->assertStringContainsString(TerimaBarangPdfFonts::FAMILY, $html);
    }

    public function test_bootstrap_uses_true_type_font_in_storage(): void
    {
        if (TerimaBarangPdfFonts::absolutePath() === null) {
            $this->markTestSkipped('CJK font file not installed.');
        }

        $this->assertTrue(TerimaBarangPdfFonts::bootstrap());
        $path = TerimaBarangPdfFonts::dompdfFontPath();
        $this->assertNotNull($path);
        $this->assertStringEndsWith('NotoSansSC-Regular.ttf', $path);
        $this->assertSame("\0\x01\0\0", file_get_contents($path, false, null, 0, 4));
    }

    public function test_fit_data_uri_returns_jpeg_payload_when_gd_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $path = public_path('images/terima-ref-0.png');
        if (! is_file($path)) {
            $this->markTestSkipped('Reference image missing.');
        }

        $uri = TerimaBarangPdfImages::fitDataUri($path, 72, 72);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
    }

    public function test_image_data_uri_returns_base64_payload(): void
    {
        $path = public_path('images/terima-ref-0.png');
        if (! is_file($path)) {
            $this->markTestSkipped('Reference image missing.');
        }

        $uri = TerimaBarangPdfData::imageDataUri($path);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/', $uri);
        $this->assertStringContainsString('base64,', $uri);
    }

    public function test_resolve_receipt_line_notes_shows_min_charge_when_billable_cbm_exceeds_actual(): void
    {
        $this->assertSame('min charge', TerimaBarangPdfData::resolveReceiptLineNotes('CBM', 0.1, 0.061));
        $this->assertSame('CBM', TerimaBarangPdfData::resolveReceiptLineNotes('CBM', 0.061, 0.061));
        $this->assertSame('CBM', TerimaBarangPdfData::resolveReceiptLineNotes('CBM', 1.0, 1.0));
    }

    public function test_build_receipt_summary_rows_split_creates_cbm_and_kg_lines(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 1000,
            'weight_price' => 10,
            'ctns' => 11,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct(['product_name' => 'High Heels', 'ctns' => 11]),
        ]));

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, BarangShippingPricing::calculate($barang)['method']);

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertCount(3, $rows);
        $this->assertSame('High Heels', $rows[0]['items_display']);
        $this->assertSame('11', $rows[0]['coly']);
        $this->assertSame('', $rows[0]['notes']);
        $this->assertSame('', $rows[0]['price_label']);
        $this->assertSame('CBM', $rows[1]['notes']);
        $this->assertSame('Rp 1,000', $rows[1]['price_label']);
        $this->assertSame('', $rows[1]['items_display']);
        $this->assertSame('-', $rows[2]['coly']);
        $this->assertSame('-', $rows[2]['volume']);
        $this->assertSame('100', $rows[2]['weight']);
        $this->assertSame('KG', $rows[2]['notes']);
        $this->assertSame('Rp 1,000 / CBM', $rows[1]['base_price_label']);
        $this->assertSame('Rp 10 / KG', $rows[2]['base_price_label']);
        $this->assertSame('Rp 1,000', $rows[2]['price_label']);
    }

    public function test_build_receipt_summary_rows_weight_split_creates_cbm_and_kg_lines(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct(['product_name' => 'ccoba', 'ctns' => 1]),
        ]));

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, BarangShippingPricing::calculate($barang)['method']);

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertCount(3, $rows);
        $this->assertSame('ccoba', $rows[0]['items_display']);
        $this->assertSame('1', $rows[0]['coly']);
        $this->assertSame('', $rows[0]['notes']);
        $this->assertSame('1.000', $rows[1]['volume']);
        $this->assertSame('500', $rows[1]['weight']);
        $this->assertSame('CBM', $rows[1]['notes']);
        $this->assertSame('Rp 5,000,000 / CBM', $rows[1]['base_price_label']);
        $this->assertSame('Rp 5,000,000', $rows[1]['price_label']);
        $this->assertSame('', $rows[2]['items_display']);
        $this->assertSame('-', $rows[2]['coly']);
        $this->assertSame('-', $rows[2]['volume']);
        $this->assertSame('100', $rows[2]['weight']);
        $this->assertSame('KG', $rows[2]['notes']);
        $this->assertSame('Rp 10,000 / KG', $rows[2]['base_price_label']);
        $this->assertSame('Rp 1,000,000', $rows[2]['price_label']);
    }

    public function test_build_receipt_summary_rows_weight_split_keeps_decimal_threshold_weight(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct(['product_name' => 'ccoba', 'ctns' => 1]),
        ]));

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertSame('500', $rows[1]['weight']);
        $this->assertSame('100', $rows[2]['weight']);
    }

    public function test_build_receipt_summary_rows_minimum_charge_weight_split(): void
    {
        $barang = new Barang([
            'total_cbm' => 0.061,
            'total_gross_weight' => 40,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 1_000_000,
            'weight_price' => 10_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'product_name' => 'Sample',
                'ctns' => 1,
                'length_cm' => 60,
                'width_cm' => 26,
                'height_cm' => 39,
            ]),
        ]));

        $pricing = BarangShippingPricing::preview(0.061, 40, 500, 1_000_000, 10_000, 0.061);
        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $pricing['method']);

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertCount(3, $rows);
        $this->assertSame('CBM', $rows[1]['notes']);
        $this->assertSame('30.5', $rows[1]['weight']);
        $this->assertSame('KG', $rows[2]['notes']);
        $this->assertSame('9.5', $rows[2]['weight']);
        $this->assertSame('Rp 61,000', $rows[1]['price_label']);
        $this->assertSame('Rp 95,000', $rows[2]['price_label']);
    }

    public function test_format_weight_column_shows_gross_when_under_cap(): void
    {
        $barang = new Barang([
            'total_cbm' => 0.216,
            'total_gross_weight' => 30,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 4_500_000,
            'weight_price' => 10_000,
        ]);

        $this->assertSame(BarangShippingPricing::METHOD_CBM, BarangShippingPricing::calculate($barang)['method']);
        $this->assertSame('30', TerimaBarangPdfData::formatWeightColumn($barang));
    }

    public function test_build_receipt_summary_rows_includes_dimensions(): void
    {
        $barang = new Barang([
            'total_cbm' => 0.216,
            'total_gross_weight' => 30,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 4_500_000,
            'ctns' => 1,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'product_name' => 'SHOES AND SANDALS',
                'ctns' => 1,
                'gross_weight_kg' => 30,
                'length_cm' => 60,
                'width_cm' => 40,
                'height_cm' => 30,
            ]),
        ]));

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertSame('60', $rows[0]['length']);
        $this->assertSame('40', $rows[0]['width']);
        $this->assertSame('30', $rows[0]['height']);
        $this->assertSame('30', $rows[0]['weight']);
    }

    public function test_build_receipt_summary_rows_lists_each_parent_product_from_form(): void
    {
        $parentWithSubs = new BarangProduct([
            'sort_order' => 2,
            'ctns' => 5,
            'product_name' => 'Box with subs',
            'gross_weight_kg' => 0,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]);
        $parentWithSubs->id = 30;

        $barang = new Barang([
            'total_cbm' => 1.252,
            'total_gross_weight' => 46,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 15_000_000,
            'ctns' => 5,
        ]);
        $barang->setRelation('products', new Collection([
            new BarangProduct([
                'sort_order' => 0,
                'product_name' => 'test',
                'ctns' => 2,
                'gross_weight_kg' => 13,
                'length_cm' => 14,
                'width_cm' => 15,
                'height_cm' => 6,
            ]),
            new BarangProduct([
                'sort_order' => 1,
                'product_name' => 'tes.2',
                'ctns' => 2,
                'gross_weight_kg' => 10,
                'length_cm' => 50,
                'width_cm' => 50,
                'height_cm' => 50,
            ]),
            $parentWithSubs,
            new BarangProduct([
                'parent_id' => 30,
                'sort_order' => 3,
                'product_name' => 'test',
                'pcs_per_box' => 2,
            ]),
            new BarangProduct([
                'parent_id' => 30,
                'sort_order' => 4,
                'product_name' => 'tes.2',
                'pcs_per_box' => 10,
            ]),
            new BarangProduct([
                'sort_order' => 5,
                'product_name' => 'Large box',
                'ctns' => 1,
                'gross_weight_kg' => 0,
                'length_cm' => 100,
                'width_cm' => 100,
                'height_cm' => 100,
            ]),
        ]));

        $productRows = TerimaBarangPdfData::buildReceiptProductDetailRows($barang);
        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);

        $this->assertCount(4, $productRows);
        $this->assertSame('test', $productRows[0]['items_display']);
        $this->assertSame('2', $productRows[0]['coly']);
        $this->assertSame('14', $productRows[0]['length']);
        $this->assertSame('0.002', $productRows[0]['volume']);
        $this->assertSame('26', $productRows[0]['weight']);
        $this->assertSame('tes.2', $productRows[1]['items_display']);
        $this->assertSame('0.250', $productRows[1]['volume']);
        $this->assertSame("test,\ntes.2", $productRows[2]['items_display']);
        $this->assertSame('5', $productRows[2]['coly']);
        $this->assertSame('Large box', $productRows[3]['items_display']);

        $pricingRow = $rows[count($rows) - 1];
        $this->assertSame('CBM', $pricingRow['notes']);
        $this->assertSame('1.252', $pricingRow['volume']);
        $this->assertSame('46', $pricingRow['weight']);
        $this->assertSame('Rp 18,780,000', $pricingRow['price_label']);
        $this->assertSame('46', TerimaBarangPdfData::formatWeightColumn($barang, true));
    }

    public function test_build_receipt_additional_rows_uses_input_names(): void
    {
        $barang = new Barang;
        $barang->setRelation('additionalPrices', new Collection([
            new BarangAdditionalPrice(['name' => 'Packing', 'price' => 75_000, 'sort_order' => 0]),
            new BarangAdditionalPrice(['name' => 'Goni', 'price' => 25_000, 'sort_order' => 1]),
        ]));

        $rows = TerimaBarangPdfData::buildReceiptAdditionalRows($barang);

        $this->assertCount(2, $rows);
        $this->assertSame('Packing', $rows[0]['items_display']);
        $this->assertSame('Rp 75,000', $rows[0]['price_label']);
        $this->assertSame('Goni', $rows[1]['items_display']);
        $this->assertSame('Rp 25,000', $rows[1]['price_label']);
        $this->assertSame('', $rows[0]['coly']);
    }

    public function test_build_packing_rows_includes_every_product(): void
    {
        $itemA = new BarangProduct([
            'sort_order' => 0,
            'ctns' => 2,
            'product_name' => 'Item A',
            'material' => 'IT0041-01',
            'pcs_per_box' => 100,
            'gross_weight_kg' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'volume_cbm' => 0.48,
            'price_per_unit_rmb' => 26,
            'total_price_rmb' => 5200,
        ]);
        $itemA->id = 1;
        $itemB = new BarangProduct([
            'sort_order' => 1,
            'ctns' => 1,
            'product_name' => 'Item B',
            'pcs_per_box' => 50,
            'gross_weight_kg' => 8,
            'length_cm' => 35,
            'width_cm' => 28,
            'height_cm' => 18,
            'volume_cbm' => 0.18,
            'price_per_unit_rmb' => 12,
            'total_price_rmb' => 600,
        ]);
        $itemB->id = 2;
        $products = new Collection([$itemA, $itemB]);

        $rows = TerimaBarangPdfData::buildPackingRows($products);

        $this->assertCount(2, $rows);
        $this->assertSame('2', $rows[0]['ctns']);
        $this->assertSame('1', $rows[1]['ctns']);
        $this->assertSame('100', $rows[0]['quantity_per_box']);
        $this->assertSame('50', $rows[1]['quantity_per_box']);
        $this->assertSame(200, $rows[0]['line_total_qty']);
        $this->assertSame(50, $rows[1]['line_total_qty']);
        $this->assertSame('Item A', $rows[0]['description']);
        $this->assertSame('Item B', $rows[1]['description']);
        $this->assertSame('40', $rows[0]['length']);
        $this->assertSame('30', $rows[0]['width']);
        $this->assertSame('20', $rows[0]['height']);
        $this->assertSame('0.048', $rows[0]['vol']);
        $this->assertSame('0.018', $rows[1]['vol']);

        $totals = TerimaBarangPdfData::summarizePackingRows($rows);
        $this->assertSame(3, $totals['ctns']);
        $this->assertSame(250, $totals['qty']);
    }

    public function test_build_packing_row_groups_merge_sub_products_under_one_coly(): void
    {
        $parent = new BarangProduct([
            'sort_order' => 1,
            'ctns' => 1,
            'product_name' => 'Parent box',
            'pcs_per_box' => 0,
            'gross_weight_kg' => 10,
            'length_cm' => 100,
            'width_cm' => 100,
            'height_cm' => 100,
            'volume_cbm' => 1.0,
        ]);
        $parent->id = 10;
        $subA = new BarangProduct([
            'parent_id' => 10,
            'sort_order' => 2,
            'product_name' => 'Sub A',
            'pcs_per_box' => 10,
            'price_per_unit_rmb' => 5,
            'total_price_rmb' => 50,
        ]);
        $subA->id = 11;
        $subB = new BarangProduct([
            'parent_id' => 10,
            'sort_order' => 3,
            'product_name' => 'Sub B',
            'pcs_per_box' => 20,
            'price_per_unit_rmb' => 3,
            'total_price_rmb' => 60,
        ]);
        $subB->id = 12;
        $subA->setRelation('parent', $parent);
        $subB->setRelation('parent', $parent);

        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([$parent, $subA, $subB]));

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['rowspan']);
        $this->assertSame('1', $groups[0]['ctns']);
        $this->assertSame('100', $groups[0]['length']);
        $this->assertSame('10.0', $groups[0]['weight']);
        $this->assertCount(3, $groups[0]['items']);
        $this->assertSame('Sub A', $groups[0]['items'][1]['description']);
        $this->assertArrayHasKey('photo_uri', $groups[0]['items'][0]);
        $this->assertArrayHasKey('photo_proof_uri', $groups[0]['items'][0]);

        $totals = TerimaBarangPdfData::summarizePackingRowGroups($groups);
        $this->assertSame(1, $totals['ctns']);
        $this->assertSame(31, $totals['qty']);
    }

    public function test_build_packing_row_groups_include_dimension_photo_uri_keys(): void
    {
        $product = new BarangProduct([
            'ctns' => 1,
            'product_name' => 'Box A',
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]);
        $product->id = 1;

        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([$product]));

        $this->assertCount(1, $groups);
        $this->assertArrayHasKey('length_photo_uri', $groups[0]);
        $this->assertArrayHasKey('width_photo_uri', $groups[0]);
        $this->assertArrayHasKey('height_photo_uri', $groups[0]);
        $this->assertNull($groups[0]['length_photo_uri']);
        $this->assertNull($groups[0]['width_photo_uri']);
        $this->assertNull($groups[0]['height_photo_uri']);
    }

    public function test_has_dimension_proof_photos_false_when_no_uploads(): void
    {
        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([
            new BarangProduct(['ctns' => 1, 'product_name' => 'A', 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]),
        ]));

        $this->assertFalse(TerimaBarangPdfData::hasDimensionProofPhotos($groups));
    }

    public function test_has_dimension_proof_photos_true_when_path_set_and_file_missing_still_false(): void
    {
        $product = new BarangProduct([
            'ctns' => 1,
            'product_name' => 'A',
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'length_photo_path' => 'barang-products/dimensions/nonexistent.png',
        ]);

        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([$product]));

        $this->assertFalse(TerimaBarangPdfData::hasDimensionProofPhotos($groups));
    }

    public function test_has_dimension_proof_photos_false_when_only_product_photo_exists(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $relativePath = 'barang-products/test-dimension-proof.jpg';
        $absolutePath = storage_path('app/public/'.$relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        if (! is_file($absolutePath)) {
            $image = imagecreatetruecolor(12, 12);
            imagejpeg($image, $absolutePath);
            imagedestroy($image);
        }

        $product = new BarangProduct([
            'ctns' => 1,
            'product_name' => 'A',
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'photo_path' => $relativePath,
        ]);

        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([$product]));

        $this->assertFalse(TerimaBarangPdfData::hasDimensionProofPhotos($groups));
    }

    public function test_has_dimension_proof_photos_true_when_p_l_t_photo_exists(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $relativePath = 'barang-products/dimensions/test-length-proof.jpg';
        $absolutePath = storage_path('app/public/'.$relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        if (! is_file($absolutePath)) {
            $image = imagecreatetruecolor(12, 12);
            imagejpeg($image, $absolutePath);
            imagedestroy($image);
        }

        $product = new BarangProduct([
            'ctns' => 1,
            'product_name' => 'A',
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'length_photo_path' => $relativePath,
        ]);

        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([$product]));

        $this->assertTrue(TerimaBarangPdfData::hasDimensionProofPhotos($groups));
    }

    public function test_show_dimension_proof_page_flag_false_when_no_embedded_uris(): void
    {
        $groups = TerimaBarangPdfData::buildPackingRowGroups(new Collection([
            new BarangProduct([
                'ctns' => 1,
                'product_name' => 'Box A',
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 30,
            ]),
        ]));

        $this->assertFalse(TerimaBarangPdfData::hasDimensionProofPhotos($groups));
    }

    public function test_resolve_customer_shipping_address_prefers_customer_then_marking(): void
    {
        $barang = new Barang(['destination' => 'Jakarta']);
        $barang->setRelation('customer', (object) ['address' => 'Jl. Customer 1']);

        $this->assertSame('Jl. Customer 1', TerimaBarangPdfData::resolveCustomerShippingAddress($barang));

        $barang->setRelation('customer', null);
        $marking = new Marking(['address_1' => 'Jl. Marking A', 'address_2' => 'Blok B']);

        $this->assertSame('Jl. Marking A, Blok B', TerimaBarangPdfData::resolveCustomerShippingAddress($barang, $marking));
        $this->assertSame('Jakarta', TerimaBarangPdfData::resolveCustomerShippingAddress($barang));
    }

    public function test_terima_barang_pdf_blade_wraps_dimension_proof_page_in_flag(): void
    {
        $blade = (string) file_get_contents(resource_path('views/exports/terima-barang-pdf.blade.php'));

        $this->assertStringContainsString('@if($showDimensionProofPage)', $blade);
        $this->assertStringContainsString('Dimension Proof Photos', $blade);
        $this->assertStringContainsString('packing-photo-table', $blade);
        $this->assertStringContainsString('$customerShippingAddress', $blade);
        $this->assertStringContainsString('Shipping Address', $blade);
        $this->assertStringNotContainsString('class="badge"', $blade);
        $this->assertStringNotContainsString("'Base Price'", $blade);
        $this->assertStringNotContainsString("'Price', '价格'", $blade);

        $proofSectionStart = strpos($blade, '@if($showDimensionProofPage)');
        $packingTableEnd = strpos($blade, '@endif', (int) strpos($blade, '@if($showPackingTable)'));

        $this->assertNotFalse($proofSectionStart);
        $this->assertNotFalse($packingTableEnd);
        $this->assertGreaterThan($packingTableEnd, $proofSectionStart);
    }

    public function test_receipt_pricing_rows_weight_split_shows_cbm_and_kg_lines(): void
    {
        $barang = new Barang([
            'total_cbm' => 1.252,
            'total_gross_weight' => 2120,
            'weight_threshold_kg' => 500,
            'price_per_cbm' => 15_000_000,
            'weight_price' => 10_000,
            'ctns' => 15,
        ]);
        $barang->setRelation('products', new \Illuminate\Support\Collection([
            new \App\Models\BarangProduct(['product_name' => 'test', 'ctns' => 2]),
        ]));

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, BarangShippingPricing::calculate($barang)['method']);

        $rows = TerimaBarangPdfData::buildReceiptSummaryRows($barang);
        $pricingRows = array_slice($rows, -2);

        $this->assertCount(2, $pricingRows);
        $this->assertSame('CBM', $pricingRows[0]['notes']);
        $this->assertSame('Rp 18,780,000', $pricingRows[0]['price_label']);
        $this->assertSame('KG', $pricingRows[1]['notes']);
        $this->assertSame('Rp 10,000 / KG', $pricingRows[1]['base_price_label']);
        $this->assertGreaterThan(0, (float) str_replace([',', 'Rp ', ' '], '', $pricingRows[1]['price_label']));
    }

    public function test_format_rmb_uses_three_decimal_precision(): void
    {
        $this->assertSame('¥12.567', TerimaBarangPdfData::formatRmb(12.567));
        $this->assertSame('¥5,200', TerimaBarangPdfData::formatRmb(5200));
    }
}
