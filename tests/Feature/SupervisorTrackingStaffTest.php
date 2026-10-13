<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SupervisorTrackingStaffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_supervisor_sees_shipment_tracking_view_scoped_to_team(): void
    {
        $supervisor = User::factory()->create(['name' => 'Gladis Tanaya']);
        $supervisor->assignRole('supervisor');

        $subordinate = User::factory()->create(['name' => 'Subordinate Sales']);
        $subordinate->assignRole('sales');

        $otherSales = User::factory()->create(['name' => 'Other Sales']);
        $otherSales->assignRole('sales');

        $supervisor->subordinates()->attach($subordinate->id);

        Marking::query()->create([
            'user_id' => $supervisor->id,
            'sales_id' => $subordinate->id,
            'code' => 'TEAM01',
            'customer_name' => 'Team Customer',
        ]);

        Marking::query()->create([
            'user_id' => $otherSales->id,
            'sales_id' => $otherSales->id,
            'code' => 'OTHER01',
            'customer_name' => 'Other Customer',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-TEAM',
            'marking' => 'TEAM01',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER01',
            'status' => Barang::STATUS_ETD,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($supervisor)
            ->get(route('tracking.staff'))
            ->assertOk()
            ->assertViewIs('tracking.staff-barang')
            ->assertSee('IDX-TEAM')
            ->assertDontSee('IDX-OTHER')
            ->assertSee(__('tracking.staff_shipment_title'))
            ->assertSee(__('tracking.staff_team_scope_hint'))
            ->assertSee(__('tracking.col_marketing'))
            ->assertSee('Subordinate Sales');
    }

    public function test_supervisor_cannot_access_container_tracking_detail(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $container = Container::query()->create([
            'partai' => 'PARTAI-1',
            'container_no' => 'CONT-1',
            'type' => '40ft',
            'status' => Container::STATUS_LOADING,
        ]);

        $this->actingAs($supervisor)
            ->getJson(route('tracking.staff.show', $container))
            ->assertForbidden();
    }

    public function test_admin_cs_still_sees_container_tracking_view(): void
    {
        Role::query()->firstOrCreate(['name' => 'admin_cs', 'guard_name' => 'web']);

        $adminCs = User::factory()->create();
        $adminCs->assignRole('admin_cs');

        $container = Container::query()->create([
            'partai' => 'PARTAI-CS',
            'container_no' => 'CONT-CS',
            'type' => '40ft',
            'status' => Container::STATUS_LOADING,
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-CS',
            'marking' => 'CS01',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($adminCs)
            ->get(route('tracking.staff'))
            ->assertOk()
            ->assertViewIs('tracking.staff')
            ->assertSee('PARTAI-CS');
    }
}
