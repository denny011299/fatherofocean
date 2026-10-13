<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TrackingEventDate;
use Carbon\Carbon;
use Tests\TestCase;

final class TrackingEventDateTest extends TestCase
{
    public function test_resolve_defaults_to_today(): void
    {
        $this->assertSame(now()->toDateString(), TrackingEventDate::resolve(null));
    }

    public function test_display_prefers_event_date_over_created_at(): void
    {
        $at = TrackingEventDate::displayAt('2026-09-01', Carbon::parse('2026-09-14 15:30:00'));

        $this->assertNotNull($at);
        $this->assertSame('2026-09-01', $at->toDateString());
        $this->assertSame('01 Sep 2026', TrackingEventDate::displayLabel('2026-09-01', '2026-09-14 15:30:00'));
    }

    public function test_display_falls_back_to_created_at(): void
    {
        $this->assertSame(
            '14 Sep 2026',
            TrackingEventDate::displayLabel(null, Carbon::parse('2026-09-14 10:02:00')),
        );
    }
}
