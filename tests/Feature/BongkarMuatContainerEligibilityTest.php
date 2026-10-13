<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\BongkarMuatScan;
use App\Models\BongkarMuatSession;
use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class BongkarMuatContainerEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_containers_endpoint_includes_eta_today_or_overdue_only_when_not_completed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 25, 10, 0, 0));

        $user = $this->userWithPermission('manage bongkar muat');

        $overdue = $this->makeContainer('PARTAI-OVERDUE', now()->copy()->subDay());
        $today = $this->makeContainer('PARTAI-TODAY', now()->copy());
        $future = $this->makeContainer('PARTAI-FUTURE', now()->copy()->addDay());
        $completed = $this->makeContainer('PARTAI-COMPLETED', now()->copy()->subDay());

        $this->attachBarang($overdue, 'IDX-OVERDUE');
        $this->attachBarang($today, 'IDX-TODAY');
        $this->attachBarang($future, 'IDX-FUTURE');
        $this->attachBarang($completed, 'IDX-COMPLETED');

        BongkarMuatSession::query()->create([
            'container_id' => $completed->id,
            'status' => BongkarMuatSession::STATUS_COMPLETED,
            'started_by' => $user->id,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('bongkar-muat.containers'));

        $response->assertOk();

        $ids = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($overdue->id, $ids);
        $this->assertContains($today->id, $ids);
        $this->assertNotContains($future->id, $ids);
        $this->assertNotContains($completed->id, $ids);
    }

    public function test_containers_endpoint_forbids_view_only_user(): void
    {
        $user = $this->userWithPermission('view bongkar muat');

        $this->actingAs($user)
            ->getJson(route('bongkar-muat.containers'))
            ->assertForbidden();
    }

    public function test_complete_unload_sets_container_status_to_unloading(): void
    {
        $user = $this->userWithPermission('manage bongkar muat');
        $container = $this->makeContainer('PARTAI-UNLOAD', now()->copy());
        $barang = $this->makeBarang('IDX-UNLOAD');

        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $session = BongkarMuatSession::query()->create([
            'container_id' => $container->id,
            'status' => BongkarMuatSession::STATUS_IN_PROGRESS,
            'started_by' => $user->id,
        ]);

        BongkarMuatScan::query()->create([
            'bongkar_muat_session_id' => $session->id,
            'barang_id' => $barang->id,
            'no_index' => $barang->no_index,
            'expected_ctns' => 1,
            'confirmed_ctns' => 1,
            'scanned_by' => $user->id,
            'scanned_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('bongkar-muat.session.complete', $session), [])
            ->assertOk();

        $this->assertSame('unloading', $container->fresh()->status);
        $this->assertSame(BongkarMuatSession::STATUS_COMPLETED, $session->fresh()->status);
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate($permission);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function makeContainer(string $partai, Carbon $eta): Container
    {
        return Container::query()->create([
            'partai' => $partai,
            'container_no' => 'CONT-'.strtoupper(substr(md5($partai), 0, 6)),
            'type' => '40ft',
            'eta' => $eta->toDateString(),
        ]);
    }

    private function attachBarang(Container $container, string $noIndex): void
    {
        $barang = $this->makeBarang($noIndex);

        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);
    }

    private function makeBarang(string $noIndex): Barang
    {
        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => 'MARK-'.$noIndex,
            'customer_name' => 'PT Test',
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETD,
            'tanggal_masuk' => now()->toDateString(),
            'ctns' => 1,
            'total_cbm' => 1,
        ]);
    }
}
