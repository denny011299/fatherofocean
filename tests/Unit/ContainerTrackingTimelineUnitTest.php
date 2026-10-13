<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Container;
use App\Models\ContainerTrackingStatus;
use App\Models\TrackingStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Uses a minimal schema (not full migrations) so SQLite in-memory tests can run
 * despite legacy MySQL-only ALTER migrations elsewhere.
 */
final class ContainerTrackingTimelineUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMinimalSchema();
    }

    public function test_update_timeline_entry_updates_event_date_and_matching_barang_row(): void
    {
        $container = Container::query()->create([
            'partai' => '552',
            'container_no' => '12',
            'type' => '40ft',
            'status' => Container::STATUS_ARRIVED,
        ]);
        $barang = Barang::query()->create([
            'no_index' => 'IDX-1',
            'marking' => 'M1',
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => '2026-08-01',
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
        ]);
        $container->barangs()->attach($barang->id);

        $entry = ContainerTrackingStatus::query()->create([
            'container_id' => $container->id,
            'status' => Container::TRACKING_ETA,
            'location' => 'Surabaya',
            'description' => 'Container tiba',
            'event_date' => '2026-08-28',
        ]);
        TrackingStatus::query()->create([
            'barang_id' => $barang->id,
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
            'description' => 'Container tiba',
            'event_date' => '2026-08-28',
        ]);

        $container->load('barangs');
        $container->updateTrackingTimelineEntry(
            $entry,
            Container::TRACKING_ETA,
            'Jakarta Port',
            'ETA dikoreksi',
            '2026-09-01',
            null,
        );

        $entry->refresh();
        $this->assertSame('Jakarta Port', $entry->location);
        $this->assertSame('ETA dikoreksi', $entry->description);
        $this->assertSame('2026-09-01', $entry->event_date?->toDateString());

        $barangRow = TrackingStatus::query()
            ->where('barang_id', $barang->id)
            ->where('status', Barang::STATUS_ETA)
            ->first();
        $this->assertNotNull($barangRow);
        $this->assertSame('Jakarta Port', $barangRow->location);
        $this->assertSame('ETA dikoreksi', $barangRow->description);
        $this->assertSame('2026-09-01', $barangRow->event_date?->toDateString());
    }

    public function test_delete_latest_timeline_falls_back_to_previous_status(): void
    {
        $container = Container::query()->create([
            'partai' => '552',
            'container_no' => '12',
            'type' => '40ft',
            'status' => Container::STATUS_ARRIVED,
        ]);
        $barang = Barang::query()->create([
            'no_index' => 'IDX-2',
            'marking' => 'M2',
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => '2026-08-01',
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
        ]);
        $container->barangs()->attach($barang->id);

        $etd = ContainerTrackingStatus::query()->create([
            'container_id' => $container->id,
            'status' => Container::TRACKING_ETD,
            'location' => 'Shanghai',
            'description' => 'Berangkat',
            'event_date' => '2026-08-20',
        ]);
        $eta = ContainerTrackingStatus::query()->create([
            'container_id' => $container->id,
            'status' => Container::TRACKING_ETA,
            'location' => 'Surabaya',
            'description' => 'Container tiba',
            'event_date' => '2026-08-28',
        ]);
        TrackingStatus::query()->create([
            'barang_id' => $barang->id,
            'status' => Barang::STATUS_ETD,
            'location' => 'Shanghai',
            'description' => 'Berangkat',
            'event_date' => '2026-08-20',
        ]);
        TrackingStatus::query()->create([
            'barang_id' => $barang->id,
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
            'description' => 'Container tiba',
            'event_date' => '2026-08-28',
        ]);

        $container->load('barangs');
        $container->deleteTrackingTimelineEntry($eta);

        $this->assertDatabaseMissing('container_tracking_statuses', ['id' => $eta->id]);
        $this->assertDatabaseHas('container_tracking_statuses', ['id' => $etd->id]);
        $this->assertSame(Container::STATUS_SHIPPED, $container->fresh()->status);
        $this->assertSame(Container::TRACKING_ETD, $container->fresh()->latestTracking?->status);
        $this->assertSame(Barang::STATUS_ETD, $barang->fresh()->status);
        $this->assertSame(
            0,
            TrackingStatus::query()
                ->where('barang_id', $barang->id)
                ->where('status', Barang::STATUS_ETA)
                ->where('location', 'Surabaya')
                ->where('description', 'Container tiba')
                ->count(),
        );
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('container_barangs');
        Schema::dropIfExists('container_tracking_statuses');
        Schema::dropIfExists('tracking_statuses');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('barangs');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table): void {
            $table->id();
            $table->string('partai', 30)->nullable();
            $table->string('container_no', 30)->nullable();
            $table->string('type', 20)->nullable();
            $table->string('status', 20)->default('open');
            $table->decimal('max_volume', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('barangs', function (Blueprint $table): void {
            $table->id();
            $table->string('no_index')->nullable();
            $table->string('marking')->nullable();
            $table->string('warehouse_origin')->nullable();
            $table->string('status', 30)->default('stuffing');
            $table->date('tanggal_masuk')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('ctns')->default(0);
            $table->decimal('total_cbm', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('container_barangs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('container_id');
            $table->unsignedBigInteger('barang_id');
            $table->timestamp('loaded_at')->nullable();
            $table->unsignedBigInteger('loaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('container_tracking_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('container_id');
            $table->string('status', 30);
            $table->string('location', 100)->nullable();
            $table->text('description')->nullable();
            $table->date('event_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tracking_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('barang_id');
            $table->string('status', 30);
            $table->string('location', 100)->nullable();
            $table->text('description')->nullable();
            $table->date('event_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }
}
