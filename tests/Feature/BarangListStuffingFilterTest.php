<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BarangListStuffingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('view barang', 'web');
        $role = Role::findOrCreate('super_admin', 'web');
        $role->givePermissionTo('view barang');
    }

    public function test_stuffing_status_filter_excludes_barang_already_in_container(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $awaiting = Barang::query()->create([
            'no_index' => 'STUFF-QUEUE',
            'marking' => 'EK/ON/TEST',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now(),
        ]);

        $loaded = Barang::query()->create([
            'no_index' => 'STUFF-502',
            'marking' => 'EK/ON/TEST',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now(),
        ]);

        $container = Container::query()->create([
            'partai' => '502',
            'type' => '20ft',
            'max_volume' => 30,
        ]);
        $container->barangs()->attach($loaded->id, ['loaded_at' => now()]);

        $response = $this->actingAs($user)->getJson('/barang/data?status=stuffing');

        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($awaiting->id, $ids);
        $this->assertNotContains($loaded->id, $ids);
    }
}
