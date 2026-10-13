<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Support\BarangShippingPricing;
use App\Support\InvoicePdfData;
use ReflectionMethod;
use Tests\TestCase;

final class InvoicePdfShippingRowsTest extends TestCase
{
    public function test_split_footer_when_weight_exceeds_per_cbm_cap(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 1000,
            'weight_price' => 10,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setRelation('products', collect());
        $barang->setRelation('additionalPrices', collect());

        $pricing = BarangShippingPricing::calculate($barang);
        $footer = $this->shippingFooter(collect([$barang]));

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $pricing['method']);
        $this->assertSame('CBM + over 100kg', $footer['keterangan']);
        $this->assertSame('Rp 1.000', $footer['kubikasi_rate']);
        $this->assertSame('Rp 1.000', $footer['kubikasi_amount']);
        $this->assertSame('Rp 10', $footer['berat_rate']);
        $this->assertSame('Rp 1.000', $footer['berat_amount']);
        $this->assertSame(2000.0, $footer['shipping_raw']);
    }

    public function test_cbm_only_keterangan_when_weight_within_cap(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 400,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setRelation('products', collect());
        $barang->setRelation('additionalPrices', collect());

        $footer = $this->shippingFooter(collect([$barang]));

        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('Rp 5.000.000', $footer['kubikasi_amount']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame(5_000_000.0, $footer['shipping_raw']);
    }

    public function test_footer_overweight_checked_on_invoice_totals_not_per_index(): void
    {
        // Index kecil over sendiri, tapi TOTAL invoice masih di bawah batas → tidak kena overweight.
        $heavySmall = new Barang([
            'no_index' => 'IDX-SMALL',
            'total_cbm' => 0.1,
            'total_gross_weight' => 120,
            'price_per_cbm' => 6_500_000,
            'weight_price' => 11_000,
            'weight_threshold_kg' => 500,
        ]);
        $heavySmall->setRelation('products', collect());
        $heavySmall->setRelation('additionalPrices', collect());

        $lightLarge = new Barang([
            'no_index' => 'IDX-LARGE',
            'total_cbm' => 3.678,
            'total_gross_weight' => 1039.6,
            'price_per_cbm' => 6_500_000,
            'weight_price' => 11_000,
            'weight_threshold_kg' => 500,
        ]);
        $lightLarge->setRelation('products', collect());
        $lightLarge->setRelation('additionalPrices', collect());

        $this->withPartai(collect([$heavySmall, $lightLarge]), '724');

        $footer = $this->shippingFooter(collect([$heavySmall, $lightLarge]));

        $this->assertSame(
            BarangShippingPricing::METHOD_WEIGHT_SPLIT,
            BarangShippingPricing::calculate($heavySmall)['method'],
        );
        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('Rp 6.500.000', $footer['kubikasi_rate']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame(3.778, $footer['billable_cbm']);
        $this->assertSame(24_557_000.0, $footer['shipping_raw']);
    }

    /**
     * Kasus HRION: harga jual beda per index, tapi min charge tidak menaikkan index kecil.
     */
    public function test_footer_mixed_cbm_rates_per_index_are_shown_and_amount_uses_each_rate(): void
    {
        $jun = new Barang([
            'no_index' => '0306.10.',
            'total_cbm' => 1.959,
            'total_gross_weight' => 130,
            'price_per_cbm' => 6_500_000,
            'weight_price' => 11_000,
            'weight_threshold_kg' => 500,
        ]);
        $jun->setAttribute('_minimum_charge_cbm', 0.1);
        $jun->setRelation('products', collect());
        $jun->setRelation('additionalPrices', collect());

        $maySmall = new Barang([
            'no_index' => '2905.1',
            'total_cbm' => 0.005,
            'total_gross_weight' => 0.5,
            'price_per_cbm' => 6_500_000,
            'weight_price' => 11_000,
            'weight_threshold_kg' => 500,
        ]);
        $maySmall->setAttribute('_minimum_charge_cbm', 0.1);
        $maySmall->setRelation('products', collect());
        $maySmall->setRelation('additionalPrices', collect());

        $mayLarge = new Barang([
            'no_index' => '2905.8',
            'total_cbm' => 1.038,
            'total_gross_weight' => 145,
            'price_per_cbm' => 5_500_000,
            'weight_price' => 11_000,
            'weight_threshold_kg' => 500,
        ]);
        $mayLarge->setAttribute('_minimum_charge_cbm', 0.1);
        $mayLarge->setRelation('products', collect());
        $mayLarge->setRelation('additionalPrices', collect());

        $this->withPartai(collect([$jun, $maySmall, $mayLarge]), '724');

        $footer = $this->shippingFooter(collect([$jun, $maySmall, $mayLarge]));

        // 1.959*6.5M + 0.005*6.5M + 1.038*5.5M = 18.475.000 (bukan 3,002 × 6.500.000)
        $this->assertSame(3.002, $footer['billable_cbm']);
        $this->assertSame(18_475_000.0, $footer['shipping_raw']);
        $this->assertCount(2, $footer['kubikasi_rows']);
        $this->assertSame('Rp 5.500.000 (2905.8)', $footer['kubikasi_rows'][0]['label']);
        $this->assertSame('Rp 5.709.000', $footer['kubikasi_rows'][0]['amount']);
        $this->assertSame('Rp 6.500.000 (0306.10., 2905.1)', $footer['kubikasi_rows'][1]['label']);
        $this->assertSame('Rp 12.766.000', $footer['kubikasi_rows'][1]['amount']);
        $this->assertSame(
            'Rp 5.500.000 (2905.8) / Rp 6.500.000 (0306.10., 2905.1)',
            $footer['kubikasi_rate'],
        );
        $this->assertSame('Rp 18.475.000', $footer['kubikasi_amount']);
    }

    public function test_product_rows_merge_index_item_and_exclude_pricing_rows(): void
    {
        $barang = new Barang([
            'no_index' => 'IDX-1',
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);
        $product = new BarangProduct([
            'product_name' => 'ccoba',
            'ctns' => 1,
            'sort_order' => 1,
            'length_cm' => 100,
            'width_cm' => 80,
            'height_cm' => 60,
            'gross_weight_kg' => 600,
        ]);
        $barang->setRelation('products', collect([$product]));
        $barang->setRelation('additionalPrices', collect());

        $pricing = BarangShippingPricing::calculate($barang);
        $rows = $this->productRows($barang);
        $footer = $this->shippingFooter(collect([$barang]));

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $pricing['method']);
        $this->assertCount(1, $rows);
        $this->assertSame('ccoba', $rows[0]['description']);
        $this->assertSame('product', $rows[0]['row_kind']);
        $this->assertTrue($rows[0]['show_merge']);
        $this->assertSame(1, $rows[0]['merge_rowspan']);
        $this->assertSame('0,480', $rows[0]['volume']);
        $this->assertSame('1', $rows[0]['coly']);
        $this->assertSame('1', $rows[0]['ctn_no']);
        $this->assertSame('100', $rows[0]['length']);
        $this->assertSame('60', $rows[0]['height']);
        $this->assertSame('80', $rows[0]['width']);
        $this->assertSame('600', $rows[0]['unit_weight']);
        $this->assertSame('600', $rows[0]['total_weight']);
        $this->assertSame('CBM + over 100kg', $footer['keterangan']);
        $this->assertSame('Rp 5.000.000', $footer['kubikasi_rate']);
        $this->assertSame('Rp 5.000.000', $footer['kubikasi_amount']);
        $this->assertSame('Rp 10.000', $footer['berat_rate']);
        $this->assertSame('Rp 1.000.000', $footer['berat_amount']);
    }

    public function test_weight_split_pdf_total_matches_shipping_footer_plus_additional(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);
        $product = new BarangProduct(['product_name' => 'ccoba', 'ctns' => 1, 'sort_order' => 1]);
        $barang->setRelation('products', collect([$product]));
        $barang->setRelation('additionalPrices', collect([
            (object) ['name' => 'goni', 'price' => 25_000, 'sort_order' => 1],
        ]));

        $footer = $this->shippingFooter(collect([$barang]));

        $totals = new ReflectionMethod(InvoicePdfData::class, 'totalsFromShippingFooter');
        $totals->setAccessible(true);
        [$beforeDiscount] = $totals->invoke(null, $footer, 0.0, 0.0, 0.0);

        $this->assertSame(6_025_000.0, $beforeDiscount);
        $this->assertSame('Rp 6.025.000', InvoicePdfData::formatRp($beforeDiscount));
        $this->assertSame('goni', $footer['additional_rows'][0]['description']);
        $this->assertSame('Rp 25.000', $footer['additional_rows'][0]['amount']);
    }

    public function test_footer_shows_extra_charge_row_without_adding_to_charge_raw(): void
    {
        $barang = new Barang([
            'total_cbm' => 0.444,
            'total_gross_weight' => 84,
            'price_per_cbm' => 5_500_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
            'extra_charge' => 100_000,
        ]);
        $barang->setRelation('products', collect());
        $barang->setRelation('additionalPrices', collect());

        $footer = $this->shippingFooter(collect([$barang]));

        $this->assertSame(__('finance.col_extra'), $footer['additional_rows'][0]['description']);
        $this->assertSame('Rp 100.000', $footer['additional_rows'][0]['amount']);
        $this->assertSame(2_442_000.0, $footer['shipping_raw']);
        $this->assertSame(2_442_000.0, $footer['charge_raw']);

        $totals = new ReflectionMethod(InvoicePdfData::class, 'totalsFromShippingFooter');
        $totals->setAccessible(true);
        [$beforeDiscount] = $totals->invoke(null, $footer, 0.0, 100_000.0, 0.0);

        $this->assertSame(2_542_000.0, $beforeDiscount);
    }

    public function test_billable_volume_for_totals_applies_marking_minimum(): void
    {
        $this->assertSame(0.027, InvoicePdfData::billableVolumeForTotals(0.027, 0.0));
        $this->assertSame(0.1, InvoicePdfData::billableVolumeForTotals(0.027, 0.1));
        $this->assertSame(0.1, InvoicePdfData::billableVolumeForTotals(0.015, 0.1));
        $this->assertSame(0.065, InvoicePdfData::billableVolumeForTotals(0.065, 0.05));
        $this->assertSame(1.264, InvoicePdfData::billableVolumeForTotals(1.264, 0.1));
    }

    public function test_table_summary_total_volume_uses_actual_sum_when_above_minimum(): void
    {
        $make = static function (float $cbm): Barang {
            $barang = new Barang([
                'total_cbm' => $cbm,
                'total_gross_weight' => 10,
                'ctns' => 1,
                'price_per_cbm' => 5_200_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $barangs = collect([
            $make(0.277),
            $make(0.027),
            $make(0.015),
        ]);
        $this->withPartai($barangs, '724');

        // Aktual: 0,277 + 0,027 + 0,015 = 0,319 (tidak naik per index ke 0,1).
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);
        $result = $summary->invoke(null, $barangs);

        $this->assertSame('0,319', $result['volume']);
    }

    public function test_table_summary_shows_actual_arrow_billable_when_invoice_total_below_minimum(): void
    {
        $make = static function (float $cbm): Barang {
            $barang = new Barang([
                'total_cbm' => $cbm,
                'total_gross_weight' => 5,
                'ctns' => 1,
                'price_per_cbm' => 5_200_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $barangs = collect([$make(0.027), $make(0.015)]);
        $this->withPartai($barangs, '724');
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);
        $result = $summary->invoke(null, $barangs);
        $footer = $this->shippingFooter($barangs);

        $this->assertSame('0,042 -> 0,100', $result['volume']);
        $this->assertSame(0.1, $footer['billable_cbm']);
        $this->assertSame(520_000.0, $footer['shipping_raw']);
    }

    public function test_volume_cell_keeps_actual_cbm_when_index_is_below_minimum(): void
    {
        $this->assertSame('0,027 -> 0,100', InvoicePdfData::formatVolumeWithMinCharge(0.027, 0.1));

        $barang = new Barang([
            'no_index' => '2005.01',
            'total_cbm' => 0.027,
            'total_gross_weight' => 13,
            'price_per_cbm' => 5_200_000,
            'weight_price' => 9_000,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setAttribute('_minimum_charge_cbm', 0.1);
        $barang->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Microphone Core',
                'ctns' => 1,
                'sort_order' => 1,
                'length_cm' => 40,
                'width_cm' => 37.5,
                'height_cm' => 18,
                'gross_weight_kg' => 13,
            ]),
        ]));
        $barang->setRelation('additionalPrices', collect());
        $this->withPartai(collect([$barang]), '724');

        $rows = $this->productRows($barang);

        $this->assertCount(1, $rows);
        $this->assertSame('0,027', $rows[0]['volume']);
    }

    public function test_volume_cell_shows_min_charge_arrow_when_unloaded(): void
    {
        $barang = new Barang([
            'no_index' => '2005.01',
            'total_cbm' => 0.027,
            'total_gross_weight' => 13,
            'price_per_cbm' => 5_200_000,
            'weight_price' => 9_000,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setAttribute('_minimum_charge_cbm', 0.1);
        $barang->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Microphone Core',
                'ctns' => 1,
                'sort_order' => 1,
                'length_cm' => 40,
                'width_cm' => 37.5,
                'height_cm' => 18,
                'gross_weight_kg' => 13,
            ]),
        ]));
        $barang->setRelation('additionalPrices', collect());
        // Belum dimuat ke container → tetap tampilkan min charge per index.
        $barang->setRelation('containers', collect());

        $rows = $this->productRows($barang);

        $this->assertSame('0,027 -> 0,100', $rows[0]['volume']);
    }

    public function test_multi_index_footer_applies_minimum_on_invoice_total_not_per_index(): void
    {
        $make = static function (float $cbm, float $gw): Barang {
            $barang = new Barang([
                'total_cbm' => $cbm,
                'total_gross_weight' => $gw,
                'price_per_cbm' => 5_200_000,
                'weight_price' => 9_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $barangs = collect([
            $make(1.264, 120),
            $make(0.054, 10),
            $make(0.065, 10),
            $make(0.065, 10),
            $make(0.015, 5),
            $make(0.027, 8),
            $make(1.327, 170),
        ]);
        $this->withPartai($barangs, '724');

        // Aktual: 2.817 ≥ min 0,1 → tagihan 2.817 × 5.200.000
        $expectedBillable = \App\Support\CbmFormat::sum(1.264, 0.054, 0.065, 0.065, 0.015, 0.027, 1.327);
        $expectedCharge = round($expectedBillable * 5_200_000, 2);

        $footer = $this->shippingFooter($barangs);
        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame($expectedBillable, $footer['billable_cbm']);
        $this->assertSame($expectedCharge, $footer['shipping_raw']);
        $this->assertSame(InvoicePdfData::formatRp($expectedCharge), $footer['kubikasi_amount']);
    }

    public function test_consolidated_invoice_uses_raw_cbm_sum_when_above_minimum(): void
    {
        $make = static function (string $index, float $cbm, float $gw): Barang {
            $barang = new Barang([
                'no_index' => $index,
                'total_cbm' => $cbm,
                'total_gross_weight' => $gw,
                'price_per_cbm' => 5_500_000,
                'weight_price' => 10_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $barangs = collect([
            $make('GZ-EK-0000035', 0.058, 10.3),
            $make('GZ-EK-0000047', 0.127, 15),
            $make('GZ-EK-0000047', 0.077, 15),
        ]);
        $this->withPartai($barangs, '724');

        $footer = $this->shippingFooter($barangs);
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);
        $table = $summary->invoke(null, $barangs);

        $this->assertSame(0.262, $footer['billable_cbm']);
        $this->assertSame(1_441_000.0, $footer['shipping_raw']);
        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame('0,262', $table['volume']);
        $this->assertSame('40.30 kg', $table['weight']);
    }

    public function test_barang_charge_before_discount_matches_invoice_pdf_total(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setRelation('products', collect([
            new BarangProduct(['product_name' => 'ccoba', 'ctns' => 1, 'sort_order' => 1]),
        ]));
        $barang->setRelation('additionalPrices', collect([
            (object) ['name' => 'goni', 'price' => 25_000, 'sort_order' => 1],
        ]));

        $this->assertSame(6_025_000.0, InvoicePdfData::barangChargeBeforeDiscountIdr($barang));
    }

    public function test_packing_nominal_differs_from_naive_cbm_times_rate(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
            'extra_charge' => 0,
        ]);
        $barang->setRelation('products', collect());
        $barang->setRelation('additionalPrices', collect());

        $naive = ($barang->total_cbm * $barang->price_per_cbm) + $barang->extra_charge;
        $invoiceAligned = InvoicePdfData::barangChargeBeforeDiscountIdr($barang);

        $this->assertSame(5_000_000.0, $naive);
        $this->assertSame(6_000_000.0, $invoiceAligned);
        $this->assertNotSame($naive, $invoiceAligned);
    }

    public function test_cbm_pdf_total_uses_display_rounded_volume(): void
    {
        $barang = new Barang([
            'total_cbm' => 1.1889,
            'total_gross_weight' => 480,
            'price_per_cbm' => 7_500_000,
            'weight_price' => 15_000,
            'weight_threshold_kg' => 500,
        ]);
        $barang->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Automatic feeder',
                'ctns' => 6,
                'sort_order' => 1,
                'volume_cbm' => 1.1889,
            ]),
        ]));
        $barang->setRelation('additionalPrices', collect());

        $footer = $this->shippingFooter(collect([$barang]));

        $totals = new ReflectionMethod(InvoicePdfData::class, 'totalsFromShippingFooter');
        $totals->setAccessible(true);
        [$beforeDiscount] = $totals->invoke(null, $footer, 0.0, 0.0, 0.0);

        $this->assertSame(8_917_500.0, $beforeDiscount);
        $this->assertSame('Rp 8.917.500', InvoicePdfData::formatRp($beforeDiscount));
    }

    public function test_same_index_merges_rows_and_unique_item_names(): void
    {
        $barangA = new Barang([
            'no_index' => '2605.1',
            'total_cbm' => 0.1,
            'total_gross_weight' => 10,
            'price_per_cbm' => 5_900_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);
        $barangA->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Imitation Accessories',
                'ctns' => 1,
                'sort_order' => 1,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 20,
                'gross_weight_kg' => 10,
            ]),
            new BarangProduct([
                'product_name' => 'Imitation Accessories',
                'ctns' => 1,
                'sort_order' => 2,
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 30,
                'gross_weight_kg' => 20,
            ]),
            new BarangProduct([
                'product_name' => 'Metal Frame',
                'ctns' => 1,
                'sort_order' => 3,
                'length_cm' => 60,
                'width_cm' => 40,
                'height_cm' => 40,
                'gross_weight_kg' => 30,
            ]),
        ]));
        $barangA->setRelation('additionalPrices', collect());

        $rows = $this->productRows($barangA);

        $this->assertCount(3, $rows);
        $this->assertTrue($rows[0]['show_merge']);
        $this->assertFalse($rows[1]['show_merge']);
        $this->assertFalse($rows[2]['show_merge']);
        $this->assertSame(3, $rows[0]['merge_rowspan']);
        $this->assertSame(3, $rows[1]['merge_rowspan']);
        $this->assertSame('Imitation Accessories, Metal Frame', $rows[0]['description']);
        $this->assertSame('Imitation Accessories, Metal Frame', $rows[1]['description']);
        $this->assertSame('40', $rows[0]['length']);
        $this->assertSame('50', $rows[1]['length']);
        $this->assertSame('60', $rows[2]['length']);
        $this->assertSame('1', $rows[0]['ctn_no']);
        $this->assertSame('2', $rows[1]['ctn_no']);
        $this->assertSame('3', $rows[2]['ctn_no']);
    }

    public function test_same_index_across_barangs_stays_one_merge_block(): void
    {
        $make = static function (string $name, float $length): Barang {
            $barang = new Barang([
                'no_index' => '2605.1',
                'total_cbm' => 0.05,
                'total_gross_weight' => 10,
                'price_per_cbm' => 5_900_000,
                'weight_price' => 10_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setRelation('products', collect([
                new BarangProduct([
                    'product_name' => $name,
                    'ctns' => 1,
                    'sort_order' => 1,
                    'length_cm' => $length,
                    'width_cm' => 30,
                    'height_cm' => 20,
                    'gross_weight_kg' => 10,
                ]),
            ]));
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $buildRows = new ReflectionMethod(InvoicePdfData::class, 'buildTableRowsFromBarangs');
        $buildRows->setAccessible(true);
        $rows = $buildRows->invoke(null, collect([
            $make('Alpha Box', 40),
            $make('Beta Box', 50),
            $make('Alpha Box', 60),
        ]));

        $this->assertCount(3, $rows);
        $this->assertTrue($rows[0]['show_merge']);
        $this->assertFalse($rows[1]['show_merge']);
        $this->assertSame(3, $rows[0]['merge_rowspan']);
        $this->assertSame('Alpha Box, Beta Box', $rows[0]['description']);
        $this->assertSame('2605.1', $rows[0]['index_no']);
        $this->assertSame('2605.1', $rows[2]['index_no']);
        $this->assertSame('1', $rows[0]['ctn_no']);
        $this->assertSame('2', $rows[1]['ctn_no']);
        $this->assertSame('3', $rows[2]['ctn_no']);
    }

    public function test_ctn_continues_across_different_indexes_in_one_frame(): void
    {
        $make = static function (string $index, string $name, int $sort): Barang {
            $barang = new Barang([
                'no_index' => $index,
                'total_cbm' => 0.1,
                'total_gross_weight' => 10,
                'price_per_cbm' => 5_900_000,
                'weight_price' => 10_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setRelation('products', collect([
                new BarangProduct([
                    'product_name' => $name,
                    'ctns' => 1,
                    'sort_order' => $sort,
                    'length_cm' => 40,
                    'width_cm' => 30,
                    'height_cm' => 20,
                    'gross_weight_kg' => 10,
                ]),
            ]));
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $buildRows = new ReflectionMethod(InvoicePdfData::class, 'buildTableRowsFromBarangs');
        $buildRows->setAccessible(true);
        $rows = $buildRows->invoke(null, collect([
            $make('2805.02', 'Accessories Imitasi', 1),
            $make('2805.2', 'Accessories Imitasi', 1),
            $make('2905.01', 'Accessories Imitasi', 1),
        ]));

        $this->assertCount(3, $rows);
        $this->assertSame('2805.02', $rows[0]['index_no']);
        $this->assertSame('2805.2', $rows[1]['index_no']);
        $this->assertSame('2905.01', $rows[2]['index_no']);
        $this->assertSame('1', $rows[0]['ctn_no']);
        $this->assertSame('2', $rows[1]['ctn_no']);
        $this->assertSame('3', $rows[2]['ctn_no']);
        $this->assertTrue($rows[0]['show_merge']);
        $this->assertTrue($rows[1]['show_merge']);
        $this->assertTrue($rows[2]['show_merge']);
        $this->assertSame(1, $rows[0]['merge_rowspan']);
    }

    /**
     * Poseidon partai 434: index kecil over sendiri (21.5kg), tapi TOTAL CBM×threshold
     * masih di atas total GW → tidak boleh kena overweight.
     */
    public function test_poseidon_partai_434_no_overweight_when_invoice_total_within_cap(): void
    {
        $make = static function (
            string $index,
            float $cbm,
            float $gw,
        ): Barang {
            $barang = new Barang([
                'no_index' => $index,
                'total_cbm' => $cbm,
                'total_gross_weight' => $gw,
                'price_per_cbm' => 5_000_000,
                'weight_price' => 10_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        // Data screenshot Poseidon: over per-index = 15+6.5 = 21.5kg, tapi total aman.
        $barangs = collect([
            $make('P-1', 0.082, 56),
            $make('P-2', 0.051, 32),
            $make('P-3', 0.055, 3.9),
            $make('P-4', 0.120, 11.6),
            $make('P-5', 1.384, 228.5),
            $make('P-6', 0.855, 228.5),
        ]);
        $this->withPartai($barangs, '434');

        $footer = $this->shippingFooter($barangs);
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);
        $table = $summary->invoke(null, $barangs);

        $this->assertTrue(InvoicePdfData::usesInvoiceLevelMinOverweight($barangs));
        $this->assertSame(2.547, $footer['billable_cbm']);
        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame(12_735_000.0, $footer['shipping_raw']);
        $this->assertSame('2,547', $table['volume']);
        // Ambang: 2.547 × 500 = 1273.5 kg > 560.5 kg total — per index tetap bisa over.
        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, BarangShippingPricing::calculate($barangs[0])['method']);
    }

    /**
     * Farit partai 434: min CBM tidak boleh naik per index (0.051→0.1 dst); total dulu baru hitung.
     */
    public function test_farit_partai_434_min_cbm_on_invoice_total_not_per_index(): void
    {
        $make = static function (string $index, float $cbm, float $gw): Barang {
            $barang = new Barang([
                'no_index' => $index,
                'total_cbm' => $cbm,
                'total_gross_weight' => $gw,
                'price_per_cbm' => 5_500_000,
                'weight_price' => 11_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        // Screenshot Farit: per-index billable 0.540 + over 1kg; total-first → 0.326 CBM, tanpa over.
        $barangs = collect([
            $make('F-1', 0.051, 33.5),
            $make('F-2', 0.006, 2.5),
            $make('F-3', 0.124, 19.5),
            $make('F-4', 0.029, 15),
            $make('F-5', 0.116, 59),
        ]);
        $this->withPartai($barangs, '434');

        $footer = $this->shippingFooter($barangs);
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);
        $table = $summary->invoke(null, $barangs);

        $this->assertTrue(InvoicePdfData::usesInvoiceLevelMinOverweight($barangs));
        $this->assertSame(0.326, $footer['billable_cbm']);
        $this->assertSame('(CBM)', $footer['keterangan']);
        $this->assertSame('-', $footer['berat_amount']);
        $this->assertSame(1_793_000.0, $footer['shipping_raw']);
        $this->assertSame('0,326', $table['volume']);
        $this->assertNotSame('0,540', $table['volume']);
    }

    public function test_unloaded_shipment_keeps_per_index_minimum(): void
    {
        $make = static function (float $cbm): Barang {
            $barang = new Barang([
                'total_cbm' => $cbm,
                'total_gross_weight' => 10,
                'ctns' => 1,
                'price_per_cbm' => 5_200_000,
                'weight_threshold_kg' => 500,
            ]);
            $barang->setAttribute('_minimum_charge_cbm', 0.1);
            $barang->setRelation('products', collect());
            $barang->setRelation('additionalPrices', collect());

            return $barang;
        };

        $barangs = collect([$make(0.027), $make(0.015)]);
        $summary = new ReflectionMethod(InvoicePdfData::class, 'buildTableSummary');
        $summary->setAccessible(true);

        $this->assertFalse(InvoicePdfData::usesInvoiceLevelMinOverweight($barangs));
        $this->assertSame('0,200', $summary->invoke(null, $barangs)['volume']);
        $this->assertSame(0.2, $this->shippingFooter($barangs)['billable_cbm']);
    }

    public function test_parse_partai_number_reads_leading_digits(): void
    {
        $this->assertSame(724, InvoicePdfData::parsePartaiNumber('724'));
        $this->assertSame(724, InvoicePdfData::parsePartaiNumber('724 / 9'));
        $this->assertSame(9, InvoicePdfData::parsePartaiNumber('9'));
        $this->assertNull(InvoicePdfData::parsePartaiNumber(''));
        $this->assertNull(InvoicePdfData::parsePartaiNumber('GZ-01'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Barang>  $barangs
     * @return \Illuminate\Support\Collection<int, Barang>
     */
    private function withPartai($barangs, string $partai = '724')
    {
        $container = new Container(['partai' => $partai]);
        foreach ($barangs as $barang) {
            $barang->setRelation('containers', collect([$container]));
        }

        return $barangs;
    }
    private function shippingFooter($barangs): array
    {
        $method = new ReflectionMethod(InvoicePdfData::class, 'buildShippingFooterFromBarangs');
        $method->setAccessible(true);

        return $method->invoke(null, $barangs);
    }

    /**
     * @return list<array<string, string>>
     */
    private function productRows(Barang $barang): array
    {
        $method = new ReflectionMethod(InvoicePdfData::class, 'productRowsForBarang');
        $method->setAccessible(true);

        return $method->invoke(null, $barang);
    }
}
