<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class BarangListDateFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_preset_today_filters_qualified_tanggal_masuk_column(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18'));

        $sql = Barang::query()
            ->filterByTanggalMasukPreset('today', null, null)
            ->toSql();

        $this->assertStringContainsString('`tanggal_masuk`', $sql);
        $this->assertStringNotContainsString('created_at', $sql);
        $this->assertStringContainsString('between', strtolower($sql));
    }

    public function test_custom_from_only_uses_tanggal_masuk(): void
    {
        $sql = Barang::query()
            ->filterByTanggalMasukPreset('custom', '2026-05-01', null)
            ->toSql();

        $this->assertStringContainsString('`tanggal_masuk`', $sql);
        $this->assertStringNotContainsString('created_at', $sql);
        $this->assertStringContainsString('>=', $sql);
    }

    public function test_null_tanggal_masuk_excluded_from_dated_results(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-18'));

        $sql = Barang::query()
            ->filterByTanggalMasukPreset('today', null, null)
            ->toSql();

        $this->assertStringNotContainsString('coalesce', strtolower($sql));
    }

    public function test_empty_preset_applies_no_date_constraint(): void
    {
        $sql = Barang::query()
            ->filterByTanggalMasukPreset('', null, null)
            ->toSql();

        $this->assertStringNotContainsString('tanggal_masuk', $sql);
    }
}
