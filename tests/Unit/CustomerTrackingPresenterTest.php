<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\MasterGudangChina;
use App\Models\TrackingStatus;
use App\Support\CustomerTrackingPresenter;
use Tests\TestCase;

final class CustomerTrackingPresenterTest extends TestCase
{
    public function test_filter_timeline_hides_auto_stuffing_entries(): void
    {
        app()->setLocale('id');

        $stuffing = new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => 'YIWU',
            'description' => 'Barang masuk proses stuffing',
        ]);
        $stuffing->id = 1;

        $loaded = new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => 'YIWU',
            'description' => 'Barang dimuat ke container 502',
        ]);
        $loaded->id = 2;

        $eta = new TrackingStatus([
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
            'description' => 'Tiba',
        ]);
        $eta->id = 3;

        $filtered = CustomerTrackingPresenter::filterTimeline([$stuffing, $loaded, $eta]);

        $this->assertCount(2, $filtered);
        $this->assertFalse($filtered->contains(fn (TrackingStatus $row): bool => (int) $row->id === 1));
        $this->assertTrue($filtered->contains(fn (TrackingStatus $row): bool => (int) $row->id === 2));
        $this->assertTrue($filtered->contains(fn (TrackingStatus $row): bool => (int) $row->id === 3));
    }

    public function test_resolve_redundant_stuffing_ids_drops_all_when_advanced_exists(): void
    {
        $deleteIds = Barang::resolveRedundantStuffingIds([
            [
                'id' => 1,
                'status' => Barang::STATUS_STUFFING,
                'description' => 'Barang masuk proses stuffing',
                'created_at' => '2026-09-01 10:00:00',
            ],
            [
                'id' => 2,
                'status' => Barang::STATUS_ETA,
                'description' => 'Tiba',
                'created_at' => '2026-09-10 10:00:00',
            ],
            [
                'id' => 3,
                'status' => Barang::STATUS_STUFFING,
                'description' => 'Barang masuk proses stuffing',
                'created_at' => '2026-09-11 10:02:00',
            ],
        ], ['Barang masuk proses stuffing', 'Goods entered stuffing process']);

        $this->assertSame([3, 1], $deleteIds);
    }

    public function test_resolve_redundant_stuffing_ids_keeps_single_warehouse_entry(): void
    {
        $deleteIds = Barang::resolveRedundantStuffingIds([
            [
                'id' => 9,
                'status' => Barang::STATUS_STUFFING,
                'description' => 'Barang masuk proses stuffing',
                'created_at' => '2026-09-11 10:02:00',
            ],
        ], ['Barang masuk proses stuffing']);

        $this->assertSame([], $deleteIds);
    }

    public function test_customer_status_label_softens_stuffing(): void
    {
        app()->setLocale('id');
        $this->assertSame('Masuk gudang', CustomerTrackingPresenter::statusLabel(Barang::STATUS_STUFFING));
        $this->assertNotSame(
            CustomerTrackingPresenter::statusLabel(Barang::STATUS_STUFFING),
            Barang::shipmentStatusLabel(Barang::STATUS_STUFFING),
        );
    }

    public function test_present_timeline_synthesizes_warehouse_row_with_tanggal_masuk(): void
    {
        app()->setLocale('id');

        $barang = new Barang([
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'YIWU',
            'tanggal_masuk' => '2026-09-10',
        ]);

        $stuffing = new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => 'YIWU',
            'description' => 'Barang masuk proses stuffing',
            'event_date' => '2026-09-10',
        ]);
        $stuffing->id = 1;

        $presented = CustomerTrackingPresenter::presentTimeline($barang, [$stuffing]);

        $this->assertCount(1, $presented);
        $row = $presented->first();
        $this->assertTrue((bool) $row->getAttribute('is_synthetic_warehouse'));
        $this->assertSame('Masuk gudang', $row->getAttribute('status_label'));
        $this->assertSame('Barang diterima di gudang', (string) $row->description);
        $this->assertSame('10 Sep 2026', $row->getAttribute('event_date_label'));
        $this->assertSame('YIWU', (string) $row->location);
        $this->assertSame('10 Sep 2026', CustomerTrackingPresenter::tanggalMasukLabel($barang));
    }

    public function test_present_timeline_keeps_non_stuffing_updates(): void
    {
        app()->setLocale('id');

        $barang = new Barang([
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => '2026-09-01',
        ]);

        $stuffing = new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'description' => 'Barang masuk proses stuffing',
        ]);
        $eta = new TrackingStatus([
            'status' => Barang::STATUS_ETA,
            'location' => 'Surabaya',
            'description' => 'Tiba',
            'event_date' => '2026-09-20',
        ]);
        $eta->id = 5;

        $presented = CustomerTrackingPresenter::presentTimeline($barang, [$stuffing, $eta]);

        $this->assertCount(1, $presented);
        $this->assertSame(5, (int) $presented->first()->id);
        $this->assertSame('20 Sep 2026', $presented->first()->getAttribute('event_date_label'));
    }

    public function test_warehouse_display_name_from_relation_and_prefix(): void
    {
        app()->setLocale('id');

        $gz = new Barang(['no_index' => 'GZ-EK-0000144', 'tanggal_masuk' => '2026-08-10']);
        $gz->setRelation('gudangChina', new MasterGudangChina(['name' => 'Gudang Guangzhou']));

        $this->assertSame('Guangzhou', CustomerTrackingPresenter::warehouseDisplayName($gz));
        $this->assertSame(
            'Tanggal Masuk Gudang Guangzhou',
            CustomerTrackingPresenter::tanggalMasukFieldLabel($gz)
        );

        $yw = new Barang(['no_index' => 'YWON-AB-0001', 'warehouse_origin' => null]);
        $this->assertSame('Yiwu', CustomerTrackingPresenter::warehouseDisplayName($yw));
        $this->assertSame(
            'Tanggal Masuk Gudang Yiwu',
            CustomerTrackingPresenter::tanggalMasukFieldLabel($yw)
        );

        $fromOrigin = new Barang(['warehouse_origin' => 'YIWU WAREHOUSE']);
        $this->assertSame('Yiwu', CustomerTrackingPresenter::warehouseDisplayName($fromOrigin));
    }
}
