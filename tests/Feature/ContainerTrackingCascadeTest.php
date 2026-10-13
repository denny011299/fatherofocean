<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ContainerTrackingCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_staff_container_status_update_cascades_to_all_barangs(): void
    {
        $user = $this->userWithPermissions(['view tracking', 'update tracking status']);
        $container = Container::query()->create([
            'partai' => 'PARTAI-TRACK',
            'container_no' => 'CONT-TRACK',
            'type' => '40ft',
            'status' => Container::STATUS_LOADING,
        ]);

        $barangA = $this->makeBarang('IDX-A');
        $barangB = $this->makeBarang('IDX-B');

        $container->barangs()->attach([$barangA->id, $barangB->id], [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $container), [
                'status' => Container::TRACKING_ETA,
                'location' => 'Surabaya',
                'description' => 'Container tiba di gudang Indonesia',
                'event_date' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(Container::STATUS_ARRIVED, $container->fresh()->status);
        $this->assertSame(Barang::STATUS_ETA, $barangA->fresh()->status);
        $this->assertSame(Barang::STATUS_ETA, $barangB->fresh()->status);
        $this->assertDatabaseHas('container_tracking_statuses', [
            'container_id' => $container->id,
            'status' => Container::TRACKING_ETA,
            'location' => 'Surabaya',
        ]);
        $this->assertDatabaseHas('tracking_statuses', [
            'barang_id' => $barangA->id,
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
        ]);
        $this->assertDatabaseHas('tracking_statuses', [
            'barang_id' => $barangB->id,
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
        ]);
    }

    public function test_loading_barang_onto_bongkar_container_keeps_bongkar_status(): void
    {
        $user = $this->userWithPermissions(['view tracking', 'update tracking status', 'load barang']);
        $container = Container::query()->create([
            'partai' => '502',
            'container_no' => '6',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
            'max_volume' => 76.20,
        ]);

        $existing = $this->makeBarang('IDX-EXISTING');
        $existing->update(['status' => Barang::STATUS_BONGKAR]);
        $container->barangs()->attach($existing->id, [
            'loaded_at' => now()->subDays(10),
            'loaded_by' => null,
        ]);

        $container->syncBongkarTracking('Jakarta', 'Container selesai bongkar', $user->id);

        $newBarang = $this->makeBarang('IDX-NEW');
        $this->assertSame(Barang::STATUS_STUFFING, $newBarang->status);

        $this->actingAs($user)
            ->postJson(route('container.load', $container), [
                'no_index' => $newBarang->no_index,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $container->refresh();
        $newBarang->refresh();

        $this->assertSame(Container::STATUS_UNLOADING, $container->status);
        $this->assertSame(Container::TRACKING_BONGKAR, $container->latestTracking?->status);
        $this->assertSame(Barang::STATUS_BONGKAR, $newBarang->status);
        $this->assertSame(
            0,
            $container->trackingStatuses()->where('status', Container::TRACKING_LOADING)->count(),
        );
        $this->assertSame(
            Container::TRACKING_BONGKAR,
            $newBarang->trackingStatuses()->value('status'),
        );
        $this->assertSame(
            0,
            $newBarang->trackingStatuses()
                ->where('description', __('tracking.auto_stuffing_description'))
                ->count(),
            'Loading onto advanced container must not create customer-facing auto stuffing update',
        );
    }

    public function test_loading_barang_onto_eta_container_keeps_eta_status(): void
    {
        $user = $this->userWithPermissions(['load barang']);
        $container = Container::query()->create([
            'partai' => 'ETA-KEEP',
            'container_no' => '9',
            'type' => '40ft',
            'status' => Container::STATUS_ARRIVED,
            'max_volume' => 76.20,
        ]);

        $container->syncEtaTracking('Port indonesia', 'Container tiba', $user->id);

        $laggingSibling = $this->makeBarang('IDX-ETA-SIBLING');
        $laggingSibling->update(['status' => Barang::STATUS_STUFFING]);
        $container->barangs()->attach($laggingSibling->id, [
            'loaded_at' => now()->subDay(),
            'loaded_by' => null,
        ]);

        $newBarang = $this->makeBarang('IDX-ETA-NEW');

        $this->actingAs($user)
            ->postJson(route('container.load', $container), [
                'no_index' => $newBarang->no_index,
            ])
            ->assertOk();

        $this->assertSame(Container::STATUS_ARRIVED, $container->fresh()->status);
        $this->assertSame(Container::TRACKING_ETA, $container->fresh()->latestTracking?->status);
        $this->assertSame(Barang::STATUS_ETA, $newBarang->fresh()->status);
        $this->assertSame(Barang::STATUS_ETA, $laggingSibling->fresh()->status);
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin_cs');

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function makeBarang(string $noIndex): Barang
    {
        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => 'MARK-'.$noIndex,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
        ]);
    }
}
