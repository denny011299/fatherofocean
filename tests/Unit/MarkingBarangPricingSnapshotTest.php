<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Support\MarkingBarangPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class MarkingBarangPricingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_to_request_snapshots_marking_prices_on_create(): void
    {
        $marking = $this->seedMarkingWithPricings();

        $request = Request::create('/', 'POST', [
            'marking' => 'SNAP-TEST',
            'cbm_type' => (string) $marking['lartas_item_id'],
            'price_per_cbm' => 1,
            'weight_price' => 2,
            'weight_threshold_kg' => 3,
        ]);

        MarkingBarangPricing::applyToRequest($request);

        $this->assertSame('2000.00', (string) $request->input('price_per_cbm'));
        $this->assertSame('9000.00', (string) $request->input('weight_price'));
        $this->assertSame('500.00', (string) $request->input('weight_threshold_kg'));
    }

    public function test_apply_to_request_preserves_existing_prices_when_marking_unchanged(): void
    {
        $marking = $this->seedMarkingWithPricings();

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SNAP',
            'marking' => 'SNAP-TEST',
            'cbm_type' => (string) $marking['lartas_item_id'],
            'warehouse_origin' => 'guangzhou',
            'status' => 'draft',
            'price_per_cbm' => 1111,
            'weight_price' => 2222,
            'weight_threshold_kg' => 333,
        ]);

        $marking['marking']->pricings()->update([
            'volume_price' => 999999,
            'gw_price' => 888888,
        ]);
        $marking['marking']->update(['max_weight_kg' => 777]);

        $request = Request::create('/', 'PUT', [
            'marking' => 'SNAP-TEST',
            'cbm_type' => (string) $marking['lartas_item_id'],
            'price_per_cbm' => 999999,
            'weight_price' => 888888,
            'weight_threshold_kg' => 777,
        ]);

        MarkingBarangPricing::applyToRequest($request, $barang);

        $this->assertSame('1111.00', (string) $request->input('price_per_cbm'));
        $this->assertSame('2222.00', (string) $request->input('weight_price'));
        $this->assertSame('333.00', (string) $request->input('weight_threshold_kg'));
    }

    public function test_apply_to_request_re_snapshots_when_cbm_type_changes(): void
    {
        $marking = $this->seedMarkingWithPricings();

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SNAP-2',
            'marking' => 'SNAP-TEST',
            'cbm_type' => (string) $marking['umum_item_id'],
            'warehouse_origin' => 'guangzhou',
            'status' => 'draft',
            'price_per_cbm' => 1000,
            'weight_price' => 5000,
            'weight_threshold_kg' => 500,
        ]);

        $request = Request::create('/', 'PUT', [
            'marking' => 'SNAP-TEST',
            'cbm_type' => (string) $marking['lartas_item_id'],
            'price_per_cbm' => 1000,
            'weight_price' => 5000,
            'weight_threshold_kg' => 500,
        ]);

        MarkingBarangPricing::applyToRequest($request, $barang);

        $this->assertSame('2000.00', (string) $request->input('price_per_cbm'));
        $this->assertSame('9000.00', (string) $request->input('weight_price'));
    }

    /**
     * @return array{marking: Marking, umum_item_id: int, lartas_item_id: int}
     */
    private function seedMarkingWithPricings(): array
    {
        $marking = Marking::query()->create([
            'code' => 'SNAP-TEST',
            'customer_name' => 'Snapshot Customer',
            'max_weight_kg' => 500,
        ]);

        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 1000,
            'gw_price' => 5000,
            'is_active' => true,
        ]);
        $lartas = MasterPricingItem::query()->create([
            'name' => 'Lartas',
            'volume_price' => 2000,
            'gw_price' => 9000,
            'is_active' => true,
        ]);

        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $umum->id,
            'volume_price' => 1000,
            'gw_price' => 5000,
        ]);
        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $lartas->id,
            'volume_price' => 2000,
            'gw_price' => 9000,
        ]);

        return [
            'marking' => $marking,
            'umum_item_id' => (int) $umum->id,
            'lartas_item_id' => (int) $lartas->id,
        ];
    }
}
