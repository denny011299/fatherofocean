<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Marking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BarangAwaitingContainerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaiting_container_load_excludes_barang_linked_to_container(): void
    {
        $marking = Marking::query()->create([
            'code' => 'AWAIT-1',
            'customer_name' => 'Await Customer',
        ]);

        $inQueue = Barang::query()->create([
            'no_index' => 'AWAIT-IN',
            'marking' => $marking->displayCode(),
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now(),
        ]);

        $loaded = Barang::query()->create([
            'no_index' => 'AWAIT-OUT',
            'marking' => $marking->displayCode(),
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now(),
        ]);

        $container = Container::query()->create([
            'partai' => 'PART-AWAIT',
            'type' => '20ft',
            'max_volume' => 30,
        ]);

        $container->barangs()->attach($loaded->id, ['loaded_at' => now()]);

        $ids = Barang::query()->awaitingContainerLoad()->pluck('id')->all();

        $this->assertContains($inQueue->id, $ids);
        $this->assertNotContains($loaded->id, $ids);
    }

    public function test_is_container_loadable_false_when_already_in_container(): void
    {
        $barang = Barang::query()->create([
            'no_index' => 'LOADABLE-1',
            'marking' => 'MK-1',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now(),
        ]);

        $container = Container::query()->create([
            'partai' => 'PART-LOAD',
            'type' => '20ft',
            'max_volume' => 30,
        ]);

        $container->barangs()->attach($barang->id, ['loaded_at' => now()]);

        $barang->load('containers');

        $this->assertFalse($barang->isContainerLoadable());
    }
}
