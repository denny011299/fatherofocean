<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Support\BarangShippingPricing;
use Tests\TestCase;

final class BarangShippingPricingTest extends TestCase
{
    public function test_plain_cbm_when_no_threshold(): void
    {
        $barang = new Barang([
            'total_cbm' => 12.5,
            'total_gross_weight' => 80,
            'price_per_cbm' => 1000,
            'weight_price' => 10,
            'weight_threshold_kg' => 0,
        ]);

        $result = BarangShippingPricing::calculate($barang);

        $this->assertSame(BarangShippingPricing::METHOD_CBM, $result['method']);
        $this->assertSame(12_500.0, $result['subtotal_idr']);
    }

    public function test_split_when_weight_exceeds_per_cbm_cap_uses_excess_kg(): void
    {
        $barang = new Barang([
            'total_cbm' => 2,
            'total_gross_weight' => 1200,
            'price_per_cbm' => 1000,
            'weight_price' => 10,
            'weight_threshold_kg' => 500,
        ]);

        $result = BarangShippingPricing::calculate($barang);

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $result['method']);
        $this->assertSame(2_000.0, $result['cbm_portion_idr']);
        // Max total weight = 2 CBM × 500 = 1000 kg, excess = 200 kg.
        $this->assertSame(2_000.0, $result['weight_portion_idr']);
        $this->assertSame(4_000.0, $result['subtotal_idr']);
    }

    public function test_resolve_sell_rates_keeps_explicit_barang_values(): void
    {
        $barang = new Barang([
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);

        $rates = BarangShippingPricing::resolveSellRates($barang);

        $this->assertSame(5_000_000.0, $rates['price_per_cbm']);
        $this->assertSame(10_000.0, $rates['weight_price']);
        $this->assertSame(500.0, $rates['weight_threshold_kg']);
    }

    public function test_resolve_weight_threshold_falls_back_to_barang_when_no_marking(): void
    {
        $barang = new Barang([
            'marking' => '',
            'weight_threshold_kg' => 420,
        ]);

        $this->assertSame(420.0, BarangShippingPricing::resolveWeightThresholdKg($barang));
    }

    public function test_weight_over_cap_splits_cbm_and_excess_kg(): void
    {
        $barang = new Barang([
            'total_cbm' => 1,
            'total_gross_weight' => 600,
            'price_per_cbm' => 5_000_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
        ]);

        $result = BarangShippingPricing::calculate($barang);

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $result['method']);
        $this->assertSame(5_000_000.0, $result['cbm_portion_idr']);
        $this->assertSame(1_000_000.0, $result['weight_portion_idr']);
        $this->assertSame(6_000_000.0, $result['subtotal_idr']);
        $this->assertEqualsWithDelta(100.0, $result['excess_kg'], 0.01);
    }

    public function test_full_weight_when_gross_weight_exceeds_threshold_without_cbm_price(): void
    {
        $barang = new Barang([
            'total_cbm' => 200,
            'total_gross_weight' => 600,
            'price_per_cbm' => 0,
            'weight_price' => 10,
            'weight_threshold_kg' => 2,
        ]);

        $result = BarangShippingPricing::calculate($barang);

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT, $result['method']);
        $this->assertSame(6000.0, $result['subtotal_idr']);
    }

    public function test_threshold_rounding_rule_keeps_five_down_and_six_up(): void
    {
        $this->assertSame(500.0, BarangShippingPricing::normalizeThresholdPerCbmKg(500.1));
        $this->assertSame(500.0, BarangShippingPricing::normalizeThresholdPerCbmKg(500.5));
        $this->assertSame(501.0, BarangShippingPricing::normalizeThresholdPerCbmKg(500.6));
    }

    public function test_minimum_charge_applies_for_small_index_cmb(): void
    {
        $result = BarangShippingPricing::preview(
            0.098,
            10,
            0,
            1_000_000,
            0,
            0.1,
        );

        $this->assertSame(BarangShippingPricing::METHOD_CBM, $result['method']);
        $this->assertSame(0.1, $result['billable_cbm']);
        $this->assertSame(100_000.0, $result['subtotal_idr']);
    }

    public function test_minimum_charge_is_calculated_per_index_not_aggregated(): void
    {
        $lineA = BarangShippingPricing::preview(0.098, 10, 0, 1_000_000, 0, 0.1);
        $lineB = BarangShippingPricing::preview(0.098, 10, 0, 1_000_000, 0, 0.1);
        $combined = BarangShippingPricing::preview(0.196, 20, 0, 1_000_000, 0, 0.1);

        $this->assertSame(200_000.0, $lineA['subtotal_idr'] + $lineB['subtotal_idr']);
        $this->assertSame(196_000.0, $combined['subtotal_idr']);
        $this->assertGreaterThan($combined['subtotal_idr'], $lineA['subtotal_idr'] + $lineB['subtotal_idr']);
    }

    public function test_minimum_charge_weight_below_max_stays_single_cbm_row(): void
    {
        $result = BarangShippingPricing::preview(
            0.03,
            25,
            500,
            1_000_000,
            10_000,
            0.061,
        );

        $this->assertSame(BarangShippingPricing::METHOD_CBM, $result['method']);
        $this->assertSame(0.061, $result['billable_cbm']);
        $this->assertSame(61_000.0, $result['subtotal_idr']);
        $this->assertSame(0.0, $result['weight_portion_idr']);
    }

    public function test_minimum_charge_weight_above_max_splits_cbm_and_kg(): void
    {
        $result = BarangShippingPricing::preview(
            0.061,
            40,
            500,
            1_000_000,
            10_000,
            0.061,
        );

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $result['method']);
        $this->assertSame(0.061, $result['billable_cbm']);
        $this->assertEqualsWithDelta(30.5, $result['cap_kg'], 0.01);
        $this->assertEqualsWithDelta(9.5, $result['excess_kg'], 0.01);
        $this->assertSame(61_000.0, $result['cbm_portion_idr']);
        $this->assertSame(95_000.0, $result['weight_portion_idr']);
        $this->assertSame(156_000.0, $result['subtotal_idr']);
    }

    public function test_minimum_charge_uses_billable_cbm_for_max_weight_when_actual_cbm_is_lower(): void
    {
        $result = BarangShippingPricing::preview(
            0.03,
            35,
            500,
            1_000_000,
            10_000,
            0.061,
        );

        $this->assertSame(BarangShippingPricing::METHOD_WEIGHT_SPLIT, $result['method']);
        $this->assertSame(0.061, $result['billable_cbm']);
        $this->assertEqualsWithDelta(30.5, $result['cap_kg'], 0.01);
        $this->assertEqualsWithDelta(4.5, $result['excess_kg'], 0.01);
        $this->assertSame(61_000.0, $result['cbm_portion_idr']);
        $this->assertSame(45_000.0, $result['weight_portion_idr']);
    }

    public function test_extras_total_sums_extra_charge_and_additional_prices(): void
    {
        $barang = new Barang(['extra_charge' => 50_000]);
        $barang->setRelation('additionalPrices', new \Illuminate\Support\Collection([
            new \App\Models\BarangAdditionalPrice(['price' => 75_000]),
            new \App\Models\BarangAdditionalPrice(['price' => 25_000]),
        ]));

        $this->assertSame(150_000.0, BarangShippingPricing::extrasTotalIdr($barang));
    }

    public function test_invoice_all_total_includes_shipping_and_all_extras(): void
    {
        $barang = new Barang([
            'total_cbm' => 10.0,
            'price_per_cbm' => 2_885_820,
            'extra_charge' => 0,
        ]);
        $barang->setRelation('additionalPrices', new \Illuminate\Support\Collection([
            new \App\Models\BarangAdditionalPrice(['price' => 1_305_000]),
        ]));

        $this->assertSame(30_163_200.0, BarangShippingPricing::invoiceAllTotalIdr($barang));
    }
}
