<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RmbFormat;
use Tests\TestCase;

final class RmbFormatTest extends TestCase
{
    public function test_rounds_to_three_decimal_places(): void
    {
        $this->assertSame(1.235, RmbFormat::round(1.2346));
        $this->assertSame(0.001, RmbFormat::round(0.0009));
    }

    public function test_line_total_multiplies_and_rounds(): void
    {
        $this->assertSame(37.5, RmbFormat::lineTotal(12.5, 3));
        $this->assertSame(400.0, RmbFormat::lineTotal(10.0, 40));
        $this->assertSame(0.0, RmbFormat::lineTotal(0, 10));
    }

    public function test_line_total_avoids_floating_point_drift(): void
    {
        $this->assertSame(0.3, RmbFormat::lineTotal(0.1, 3));
    }

    public function test_format_shows_up_to_three_decimals(): void
    {
        $this->assertSame('1,234.5', RmbFormat::format(1234.5));
        $this->assertSame('1,234.567', RmbFormat::format(1234.567));
    }

    public function test_parse_input_handles_rmb_mask_format(): void
    {
        $this->assertSame('1234.567', RmbFormat::parseInput('¥ 1,234.567'));
        $this->assertNull(RmbFormat::parseInput(''));
    }

    public function test_format_with_symbol(): void
    {
        $this->assertSame('¥12.567', RmbFormat::formatWithSymbol(12.567));
    }

    public function test_line_total_with_ctns(): void
    {
        $this->assertSame(5200.0, RmbFormat::lineTotal(26.0, 100, 2));
    }
}
