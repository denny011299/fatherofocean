<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\TrackingController;
use App\Models\Barang;
use App\Models\TrackingStatus;
use ReflectionMethod;
use Tests\TestCase;

final class TrackingControllerDisplayTest extends TestCase
{
    public function test_decorate_tracking_display_uses_shipment_fallback_when_latest_tracking_is_stale(): void
    {
        $barang = new Barang([
            'status' => Barang::STATUS_ETA,
            'destination' => 'Surabaya',
        ]);
        $barang->setRelation('latestTracking', new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => 'Guangzhou',
            'description' => 'Barang dimuat ke container ABC',
        ]));

        $decorated = $this->decorate($barang);

        $this->assertSame('Surabaya', $decorated->display_tracking_location);
        $this->assertSame(__('tracking.auto_eta_description'), $decorated->display_tracking_description);
    }

    public function test_decorate_tracking_display_keeps_latest_tracking_when_status_matches(): void
    {
        $barang = new Barang([
            'status' => Barang::STATUS_STUFFING,
            'destination' => 'Surabaya',
        ]);
        $barang->setRelation('latestTracking', new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => 'Guangzhou',
            'description' => __('tracking.auto_stuffing_description'),
        ]));

        $decorated = $this->decorate($barang);

        $this->assertSame('Guangzhou', $decorated->display_tracking_location);
        $this->assertSame(__('tracking.auto_stuffing_description'), $decorated->display_tracking_description);
    }

    private function decorate(Barang $barang): Barang
    {
        $method = new ReflectionMethod(TrackingController::class, 'decorateTrackingDisplay');
        $method->setAccessible(true);

        /** @var Barang $decorated */
        $decorated = $method->invoke(new TrackingController(), $barang);

        return $decorated;
    }
}
