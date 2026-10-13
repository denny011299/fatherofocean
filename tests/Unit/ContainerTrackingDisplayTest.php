<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\TrackingController;
use App\Models\Barang;
use App\Models\Container;
use App\Models\ContainerTrackingStatus;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

final class ContainerTrackingDisplayTest extends TestCase
{
    public function test_tracking_status_from_current_state_handles_unloading_stage(): void
    {
        $mixedBarangs = collect([
            new Barang(['status' => Barang::STATUS_ETA]),
            new Barang(['status' => Barang::STATUS_BONGKAR]),
        ]);
        $doneBarangs = collect([
            new Barang(['status' => Barang::STATUS_BONGKAR]),
            new Barang(['status' => Barang::STATUS_BONGKAR]),
        ]);

        $this->assertSame(
            Container::TRACKING_ETA,
            Container::trackingStatusFromCurrentState(Container::STATUS_UNLOADING, $mixedBarangs),
        );
        $this->assertSame(
            Container::TRACKING_BONGKAR,
            Container::trackingStatusFromCurrentState(Container::STATUS_UNLOADING, $doneBarangs),
        );
    }

    public function test_decorate_container_tracking_display_aggregates_summary_and_prefers_latest_timeline(): void
    {
        $container = new Container([
            'partai' => 'PARTAI-01',
            'container_no' => 'CONT-001',
            'status' => Container::STATUS_SHIPPED,
        ]);
        $container->setRelation('latestTracking', new ContainerTrackingStatus([
            'status' => Container::TRACKING_ETD,
            'location' => 'Surabaya',
            'description' => 'Container berangkat ke tujuan',
        ]));
        $container->setRelation('barangs', collect([
            $this->makeBarang('IDX-01', 'MARK-A', 'Alice', 'Surabaya', 2, 1.5, Barang::STATUS_ETD),
            $this->makeBarang('IDX-02', 'MARK-B', 'Bob', 'Jakarta', 3, 2.0, Barang::STATUS_ETD),
        ]));

        $decorated = $this->decorate($container);

        $this->assertSame('PARTAI-01 / CONT-001', $decorated->container_label);
        $this->assertSame(2, $decorated->shipments_count);
        $this->assertSame(5, $decorated->total_ctns_display);
        $this->assertSame(3.5, $decorated->total_cbm_display);
        $this->assertSame('MARK-A, MARK-B', $decorated->markings_list);
        $this->assertSame('Alice, Bob', $decorated->customers_list);
        $this->assertSame('Surabaya, Jakarta', $decorated->destinations_list);
        $this->assertSame(Container::TRACKING_ETD, $decorated->display_tracking_status);
        $this->assertSame('Surabaya', $decorated->display_tracking_location);
        $this->assertSame('Container berangkat ke tujuan', $decorated->display_tracking_description);
    }

    public function test_tracking_status_beyond_loading_detects_eta_and_bongkar(): void
    {
        $eta = new Container(['status' => Container::STATUS_ARRIVED]);
        $eta->setRelation('latestTracking', new ContainerTrackingStatus([
            'status' => Container::TRACKING_ETA,
        ]));
        $this->assertSame(Container::TRACKING_ETA, $eta->trackingStatusBeyondLoading());

        $bongkar = new Container(['status' => Container::STATUS_UNLOADING]);
        $bongkar->setRelation('latestTracking', new ContainerTrackingStatus([
            'status' => Container::TRACKING_BONGKAR,
        ]));
        $this->assertSame(Container::TRACKING_BONGKAR, $bongkar->trackingStatusBeyondLoading());

        $loading = new Container(['status' => Container::STATUS_LOADING]);
        $loading->setRelation('latestTracking', new ContainerTrackingStatus([
            'status' => Container::TRACKING_LOADING,
        ]));
        $this->assertNull($loading->trackingStatusBeyondLoading());
    }

    public function test_sync_loading_tracking_does_not_regress_past_bongkar(): void
    {
        $container = new Container([
            'partai' => '502',
            'status' => Container::STATUS_UNLOADING,
        ]);
        $latest = new ContainerTrackingStatus([
            'status' => Container::TRACKING_BONGKAR,
            'location' => 'Jakarta',
            'description' => 'Container selesai bongkar',
        ]);
        $container->setRelation('latestTracking', $latest);

        $result = $container->syncLoadingTracking('Guangzhou', 'should be ignored');

        $this->assertSame($latest, $result);
        $this->assertSame(Container::STATUS_UNLOADING, $container->status);
    }

    public function test_barang_shipment_progress_rank_orders_pipeline(): void
    {
        $this->assertSame(1, Container::barangShipmentProgressRank(Barang::STATUS_STUFFING));
        $this->assertSame(1, Container::barangShipmentProgressRank(Barang::STATUS_INBOUND));
        $this->assertSame(2, Container::barangShipmentProgressRank(Barang::STATUS_ETD));
        $this->assertSame(3, Container::barangShipmentProgressRank(Barang::STATUS_ETA));
        $this->assertSame(4, Container::barangShipmentProgressRank(Barang::STATUS_BONGKAR));
        $this->assertTrue(
            Container::barangShipmentProgressRank(Barang::STATUS_STUFFING)
            < Container::barangShipmentProgressRank(Barang::STATUS_ETA),
        );
    }

    public function test_barang_status_for_tracking_maps_bea_cukai_to_eta(): void
    {
        $this->assertSame(Barang::STATUS_ETA, Container::barangStatusForTrackingStatus(Container::TRACKING_ETA));
        $this->assertSame(Barang::STATUS_ETA, Container::barangStatusForTrackingStatus(Container::TRACKING_BEA_CUKAI));
        $this->assertSame(Barang::STATUS_BONGKAR, Container::barangStatusForTrackingStatus(Container::TRACKING_BONGKAR));
    }

    public function test_resolve_redundant_loading_ids_keeps_earliest_before_eta(): void
    {
        $deleteIds = Container::resolveRedundantLoadingIds([
            ['id' => 1, 'created_at' => '2026-06-27 11:28:09'],
            ['id' => 2, 'created_at' => '2026-06-29 12:05:57'],
            ['id' => 3, 'created_at' => '2026-07-01 16:01:56'],
            ['id' => 4, 'created_at' => '2026-07-11 15:45:04'],
        ], '2026-07-01 14:17:11');

        $this->assertSame([2, 3, 4], $deleteIds);
    }

    public function test_resolve_redundant_loading_ids_drops_all_when_only_after_eta(): void
    {
        $deleteIds = Container::resolveRedundantLoadingIds([
            ['id' => 10, 'created_at' => '2026-07-11 15:45:04'],
        ], '2026-07-01 14:17:11');

        $this->assertSame([10], $deleteIds);
    }

    private function makeBarang(
        string $noIndex,
        string $marking,
        string $customerName,
        string $destination,
        int $ctns,
        float $cbm,
        string $status,
    ): Barang {
        $barang = new Barang([
            'no_index' => $noIndex,
            'marking' => $marking,
            'destination' => $destination,
            'ctns' => $ctns,
            'total_cbm' => $cbm,
            'status' => $status,
        ]);
        $barang->setRelation('customer', new User(['name' => $customerName]));

        return $barang;
    }

    private function decorate(Container $container): Container
    {
        $method = new ReflectionMethod(TrackingController::class, 'decorateContainerTrackingDisplay');
        $method->setAccessible(true);

        /** @var Container $decorated */
        $decorated = $method->invoke(new TrackingController(), $container);

        return $decorated;
    }
}
