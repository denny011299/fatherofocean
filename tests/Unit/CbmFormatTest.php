<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CbmFormat;
use App\Support\CbmVolume;
use PHPUnit\Framework\TestCase;

final class CbmFormatTest extends TestCase
{
    public function test_fourth_decimal_digit_zero_to_five_rounds_down(): void
    {
        $this->assertSame(1.234, CbmFormat::truncate(1.23444));
        $this->assertSame(1.234, CbmFormat::truncate(1.23445));
        $this->assertSame(1.234, CbmFormat::truncate(1.23456));
        $this->assertSame(1.234, CbmFormat::truncate(1.23459));
        $this->assertSame(1.235, CbmFormat::truncate(1.23544));
    }

    public function test_fourth_decimal_digit_six_to_nine_rounds_up(): void
    {
        $this->assertSame(1.235, CbmFormat::truncate(1.23464));
        $this->assertSame(7.086, CbmFormat::truncate(7.0859));
        $this->assertSame(7.086, CbmFormat::truncate(7.08599));
        $this->assertSame(0.106, CbmFormat::truncate(0.10571));
    }

    public function test_truncate_clamps_negative_to_zero(): void
    {
        $this->assertSame(0.0, CbmFormat::truncate(-1.5));
    }

    public function test_small_positive_cbm_rounds_with_three_decimals(): void
    {
        $raw = (100 * 100 * 100) / CbmVolume::DIMENSION_DIVISOR;

        $this->assertSame(1.0, CbmFormat::truncate($raw));
    }

    public function test_format_shows_three_decimals(): void
    {
        $this->assertSame('7.086', CbmFormat::format(7.0859));
        $this->assertSame('7.086', CbmFormat::format(7.08599));
        $this->assertSame('1.500', CbmFormat::format(1.5));
        $this->assertSame('1.234', CbmFormat::format(1.23445));
    }

    public function test_sum_normalizes_aggregate(): void
    {
        $this->assertSame(3.5, CbmFormat::sum(1.5, 2.0));
        $this->assertSame(1.189, CbmFormat::sum(1.1889, 0.0001));
    }

    public function test_barang_total_matches_sum_of_truncated_line_cbm(): void
    {
        // Screenshot case: per-row displayed CBM must equal footer (no separate raw-sum path).
        $lines = [
            CbmVolume::line(60, 23.5, 53, 2),
            CbmVolume::line(27, 15, 15, 1),
            CbmVolume::line(41, 34, 30, 16),
            CbmVolume::line(45.5, 35, 34, 16),
        ];

        $this->assertSame(0.149, $lines[0]);
        $this->assertSame(0.006, $lines[1]);
        $this->assertSame(0.669, $lines[2]);
        $this->assertSame(0.866, $lines[3]);
        $this->assertSame(1.69, CbmFormat::sum(...$lines));
        $this->assertSame('1.690', CbmFormat::format(CbmFormat::sum(...$lines)));
    }

    public function test_sum_of_truncated_lines_is_preferred_over_raw_sum_for_display_consistency(): void
    {
        $rawA = 5.478_25;
        $rawB = 4.957_35;

        $sumOfTruncatedLines = CbmFormat::sum(
            CbmFormat::truncate($rawA),
            CbmFormat::truncate($rawB),
        );
        $totalFromRawSum = CbmFormat::truncate($rawA + $rawB);

        $this->assertSame(10.435, $sumOfTruncatedLines);
        // Raw-sum path can differ by 0.001 — form/invoice must follow truncated lines.
        $this->assertSame(10.436, $totalFromRawSum);
        $this->assertNotSame($sumOfTruncatedLines, $totalFromRawSum);
    }
}
