<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MarkingMinimumChargeGlobalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_schema_has_global_minimum_charge_column(): void
    {
        $this->assertTrue(Schema::hasColumn('markings', 'minimum_charge'));
        $this->assertFalse(Schema::hasColumn('marking_pricings', 'minimum_charge'));
    }

    public function test_update_logs_minimum_charge_change(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $marking = Marking::query()->create([
            'code' => 'MIN-TEST',
            'customer_name' => 'Test Customer',
            'minimum_charge' => 100,
        ]);

        $item = MasterPricingItem::query()->create([
            'name' => 'General',
            'volume_price' => 5000,
            'gw_price' => 100,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/marking/' . $marking->id, [
            'code' => 'MIN-TEST',
            'customer_name' => 'Test Customer',
            'minimum_charge' => 250,
            'pricings' => [
                ['pricing_item_id' => $item->id],
            ],
        ]);

        $response->assertOk();

        $log = ActivityLog::query()
            ->where('action', 'Marking minimum CBM charge updated')
            ->where('model_id', $marking->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('100.00', (string) ($log->old_values['minimum_charge'] ?? ''));
        $this->assertSame('250.00', (string) ($log->new_values['minimum_charge'] ?? ''));

        $marking->refresh();
        $this->assertSame('250.00', (string) $marking->minimum_charge);
        $this->assertSame(1, MarkingPricing::query()->where('marking_id', $marking->id)->count());
    }
}
