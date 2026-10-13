<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use Tests\TestCase;

final class BarangPricingItemFilterTest extends TestCase
{
    public function test_empty_filter_applies_no_constraint(): void
    {
        $sql = Barang::query()->filterByPricingItemId('')->toSql();

        $this->assertStringNotContainsString('cbm_type', $sql);
    }

    public function test_pricing_item_filter_targets_cbm_type_column(): void
    {
        $sql = Barang::query()->filterByPricingItemId(99)->toSql();

        $this->assertStringContainsString('cbm_type', $sql);
    }

    public function test_multiple_pricing_item_ids_use_or_grouping(): void
    {
        $sql = Barang::query()->filterByPricingItemId([1, 2])->toSql();

        $this->assertStringContainsString('cbm_type', $sql);
        $this->assertGreaterThanOrEqual(2, substr_count(strtolower($sql), 'or'));
    }

    public function test_empty_array_filter_applies_no_constraint(): void
    {
        $sql = Barang::query()->filterByPricingItemId([])->toSql();

        $this->assertStringNotContainsString('cbm_type', $sql);
    }
}
