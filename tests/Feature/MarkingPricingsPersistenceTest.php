<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class MarkingPricingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_update_persists_pricings_and_data_lists_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00', 'Asia/Jakarta'));

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $marking = Marking::query()->create([
            'code' => 'PRICE-TEST',
            'customer_name' => 'Pricing Customer',
            'effective_start' => '2026-05-20',
        ]);

        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 500000,
            'gw_price' => 10000,
            'is_active' => true,
        ]);
        $lartas = MasterPricingItem::query()->create([
            'name' => 'Lartas',
            'volume_price' => 100000,
            'gw_price' => 17000,
            'is_active' => true,
        ]);

        $this->actingAs($user)->putJson('/marking/' . $marking->id, [
            'code' => 'PRICE-TEST',
            'customer_name' => 'Pricing Customer',
            'effective_start' => '2026-05-20',
            'pricings' => [
                [
                    'pricing_item_id' => $umum->id,
                    'item_name' => 'Barang Umum Customer',
                    'volume_price' => 500000,
                    'gw_price' => 10000,
                ],
                ['pricing_item_id' => $lartas->id, 'volume_price' => 100000, 'gw_price' => 17000],
            ],
        ])->assertOk();

        $this->assertSame(2, MarkingPricing::query()->where('marking_id', $marking->id)->count());
        $this->assertSame(
            'Barang Umum Customer',
            MarkingPricing::query()
                ->where('marking_id', $marking->id)
                ->where('pricing_item_id', $umum->id)
                ->value('item_name'),
        );

        $payload = $this->actingAs($user)
            ->getJson('/marking/data')
            ->assertOk()
            ->json();

        $row = collect($payload)->firstWhere('id', $marking->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['pricing_is_effective']);
        $this->assertCount(2, $row['pricings']);
        $this->assertSame('500000.00', (string) $row['pricings'][0]['volume_price']);
        $this->assertSame('Barang Umum Customer', $row['pricings'][0]['item_name'] ?? null);
    }

    public function test_pricings_api_returns_item_name_with_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00', 'Asia/Jakarta'));

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $marking = Marking::query()->create([
            'code' => 'API-ITEM-NAME',
            'customer_name' => 'API Item Name Customer',
            'effective_start' => '2026-05-25',
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 500000,
            'gw_price' => 10000,
            'is_active' => true,
        ]);
        $marking->pricings()->create([
            'pricing_item_id' => $item->id,
            'item_name' => 'Nama dari Marking',
            'volume_price' => 500000,
            'gw_price' => 10000,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/marking/' . $marking->id . '/pricings')
            ->assertOk()
            ->json();

        $this->assertFalse($response['pricing_is_effective']);
        $this->assertCount(1, $response['pricings']);
        $this->assertSame('Nama dari Marking', $response['pricings'][0]['item_name'] ?? null);
        $this->assertSame('500000.00', (string) $response['pricings'][0]['volume_price']);
    }

    public function test_pricings_api_returns_rows_even_when_not_yet_effective(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00', 'Asia/Jakarta'));

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $marking = Marking::query()->create([
            'code' => 'API-PRICE',
            'customer_name' => 'API Customer',
            'effective_start' => '2026-05-25',
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 500000,
            'gw_price' => 10000,
            'is_active' => true,
        ]);
        $marking->pricings()->create([
            'pricing_item_id' => $item->id,
            'volume_price' => 500000,
            'gw_price' => 10000,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/marking/' . $marking->id . '/pricings')
            ->assertOk()
            ->json();

        $this->assertFalse($response['pricing_is_effective']);
        $this->assertCount(1, $response['pricings']);
        $this->assertSame('500000.00', (string) $response['pricings'][0]['volume_price']);
    }

    public function test_update_without_manage_pricing_permission_does_not_wipe_pricings(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('admin_cs');
        $this->assertFalse($editor->can('manage marking pricing'));

        $marking = Marking::query()->create([
            'code' => 'KEEP-PRICE',
            'customer_name' => 'Keep Pricing',
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 250000,
            'gw_price' => 5000,
            'is_active' => true,
        ]);
        $marking->pricings()->create([
            'pricing_item_id' => $item->id,
            'volume_price' => 250000,
            'gw_price' => 5000,
        ]);

        $this->actingAs($editor)->putJson('/marking/' . $marking->id, [
            'code' => 'KEEP-PRICE',
            'customer_name' => 'Keep Pricing Updated',
        ])->assertOk();

        $this->assertSame(1, MarkingPricing::query()->where('marking_id', $marking->id)->count());
        $pricing = $marking->pricings()->with('pricingItem')->first();
        $this->assertNotNull($pricing);
        $this->assertSame('250000.00', (string) $pricing->volume_price);
    }

    public function test_pricings_api_returns_marking_stored_prices_not_master(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $marking = Marking::query()->create([
            'code' => 'MASTER-PRICE',
            'customer_name' => 'Master Price Customer',
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 400000,
            'gw_price' => 8000,
            'is_active' => true,
        ]);
        $marking->pricings()->create([
            'pricing_item_id' => $item->id,
            'volume_price' => 420000,
            'gw_price' => 8500,
        ]);

        $item->update(['volume_price' => 550000, 'gw_price' => 12000]);

        $response = $this->actingAs($user)
            ->getJson('/marking/' . $marking->id . '/pricings')
            ->assertOk()
            ->json();

        $this->assertSame('420000.00', (string) $response['pricings'][0]['volume_price']);
        $this->assertSame('8500.00', (string) $response['pricings'][0]['gw_price']);
    }

    public function test_gudang_china_can_load_pricings_without_view_marking_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin_gudang_china');

        $marking = Marking::query()->create([
            'code' => 'GC-PRICE',
            'customer_name' => 'Gudang Customer',
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 400000,
            'gw_price' => 8000,
            'is_active' => true,
        ]);
        $marking->pricings()->create([
            'pricing_item_id' => $item->id,
            'volume_price' => 420000,
            'gw_price' => 8500,
        ]);

        $this->actingAs($user)
            ->getJson('/marking/' . $marking->id . '/pricings')
            ->assertOk()
            ->assertJsonPath('pricings.0.volume_price', '420000.00');
    }
}
