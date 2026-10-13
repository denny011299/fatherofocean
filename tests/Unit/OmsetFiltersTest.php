<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OmsetFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class OmsetFiltersTest extends TestCase
{
    public function test_date_preset_30d_resolves_bounds(): void
    {
        $filters = OmsetFilters::fromRequest(Request::create('/export/omset', 'GET', [
            'date_preset' => '30d',
        ]));

        $this->assertSame('30d', $filters->datePreset);
        $this->assertNotNull($filters->tanggalFrom);
        $this->assertNotNull($filters->tanggalTo);
        $this->assertTrue($filters->hasActiveFilter());
    }

    public function test_partai_filter_is_preserved_in_query_array(): void
    {
        $filters = OmsetFilters::fromRequest(Request::create('/export/omset', 'GET', [
            'date_preset' => '7d',
            'partai' => '450',
        ]));

        $this->assertSame('450', $filters->partai);
        $this->assertSame('450', $filters->toQueryArray()['partai']);
    }

    public function test_legacy_period_params_map_to_custom_range(): void
    {
        $filters = OmsetFilters::fromRequest(Request::create('/export/omset', 'GET', [
            'period' => 'bulanan',
            'year' => 2026,
            'month' => 7,
        ]));

        $this->assertSame('custom', $filters->datePreset);
        $this->assertSame('2026-07-01', $filters->tanggalFrom);
        $this->assertSame('2026-07-31', $filters->tanggalTo);
    }

    public function test_default_for_omset_uses_current_year(): void
    {
        $filters = OmsetFilters::defaultForOmset();

        $this->assertSame('this_year', $filters->datePreset);
        $this->assertSame(now()->year.'-01-01', $filters->tanggalFrom);
        $this->assertSame(now()->year.'-12-31', $filters->tanggalTo);
    }

    public function test_empty_date_preset_param_means_no_date_filter(): void
    {
        $filters = OmsetFilters::fromRequest(Request::create('/export/omset', 'GET', [
            'date_preset' => '',
        ]));

        $this->assertSame('', $filters->datePreset);
        $this->assertNull($filters->tanggalFrom);
        $this->assertNull($filters->tanggalTo);
    }
}
