<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\CompanyRevenueItemModal;
use App\Models\MasterPricingItem;
use App\Models\SystemSetting;
use App\Support\BarangCostPricing;
use App\Support\CompanyRevenueSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompanyRevenueModalRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_modal_rates_always_zero(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_VOLUME_PRICE, '575000');
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_GW_PRICE, '5500');

        $rates = CompanyRevenueSettings::modalRates();

        $this->assertSame(0.0, $rates['volume_price']);
        $this->assertSame(0.0, $rates['gw_price']);
    }

    public function test_barang_cost_pricing_returns_zero_when_no_override_or_item_modal(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_VOLUME_PRICE, '575000');
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_GW_PRICE, '5500');

        $barang = Barang::query()->create([
            'no_index' => 'IDX-MODAL-1',
            'marking' => 'TEST',
            'status' => 'stuffing',
        ]);

        $rates = BarangCostPricing::modalRatesForBarang($barang);

        $this->assertSame(0.0, $rates['volume_price']);
        $this->assertSame(0.0, $rates['gw_price']);
    }

    public function test_barang_cost_pricing_uses_item_modal_when_cbm_type_matches(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_VOLUME_PRICE, '100000');
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_GW_PRICE, '1000');

        $item = MasterPricingItem::query()->create([
            'name' => 'Lartas',
            'volume_price' => 0,
            'gw_price' => 0,
            'is_active' => true,
        ]);

        CompanyRevenueItemModal::query()->create([
            'pricing_item_id' => $item->id,
            'cost_volume_price' => 620_000,
            'cost_gw_price' => 6_000,
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-MODAL-3',
            'marking' => 'TEST',
            'cbm_type' => (string) $item->id,
            'status' => 'stuffing',
        ]);

        $rates = BarangCostPricing::modalRatesForBarang($barang);

        $this->assertSame(620_000.0, $rates['volume_price']);
        $this->assertSame(6_000.0, $rates['gw_price']);
    }

    public function test_resolve_modal_rates_returns_zero_when_item_not_configured(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_VOLUME_PRICE, '575000');
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_GW_PRICE, '5500');

        $item = MasterPricingItem::query()->create([
            'name' => 'Kosmetik',
            'volume_price' => 0,
            'gw_price' => 0,
            'is_active' => true,
        ]);

        $rates = CompanyRevenueSettings::resolveModalRates((string) $item->id);

        $this->assertSame(0.0, $rates['volume_price']);
        $this->assertSame(0.0, $rates['gw_price']);
    }

    public function test_barang_cost_pricing_prefers_shipment_override(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_VOLUME_PRICE, '575000');
        SystemSetting::setValue(CompanyRevenueSettings::KEY_COST_GW_PRICE, '5500');

        $barang = Barang::query()->create([
            'no_index' => 'IDX-MODAL-2',
            'marking' => 'TEST',
            'cost_price_per_cbm' => 600_000,
            'cost_weight_price' => 6_000,
            'status' => 'stuffing',
        ]);

        $rates = BarangCostPricing::modalRatesForBarang($barang);

        $this->assertSame(600_000.0, $rates['volume_price']);
        $this->assertSame(6_000.0, $rates['gw_price']);
    }
}
