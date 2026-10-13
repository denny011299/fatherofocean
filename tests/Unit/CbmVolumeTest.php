<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CbmFormat;
use App\Support\CbmVolume;
use PHPUnit\Framework\TestCase;

final class CbmVolumeTest extends TestCase
{
    public function test_line_volume_divides_by_one_million(): void
    {
        $this->assertSame(0.001, CbmVolume::line(14, 15, 6, 1));
        $this->assertSame(0.002, CbmVolume::line(14, 15, 6, 2));
    }

    public function test_unit_volume_matches_single_ctn_line(): void
    {
        $this->assertSame(CbmVolume::line(50, 50, 50, 1), CbmVolume::unit(50, 50, 50));
        $this->assertSame(0.125, CbmVolume::unit(50, 50, 50));
    }

    public function test_real_world_dimensions_with_ctns(): void
    {
        $this->assertSame(1.160, CbmVolume::line(67, 52, 37, 9));
    }

    public function test_raw_line_uses_same_divisor_as_format(): void
    {
        $raw = CbmVolume::rawLine(33.33, 33.33, 33.33, 10);
        $this->assertSame(CbmFormat::truncate($raw), CbmVolume::line(33.33, 33.33, 33.33, 10));
    }
}
