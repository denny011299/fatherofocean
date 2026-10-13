<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Marking;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

final class MarkingWithoutCodeColumnSqlTest extends TestCase
{
    /** @param array<string, bool> $columns */
    private function seedSchemaCache(array $columns): void
    {
        $ref = new ReflectionClass(Marking::class);
        $prop = $ref->getProperty('schemaColumnCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $columns);
    }

    public function test_shipping_subquery_omits_code_column_when_absent(): void
    {
        $this->seedSchemaCache([
            'markings.code' => false,
            'markings.marking_code' => false,
            'barangs.marking_id' => false,
            'barangs.marking' => true,
            'barangs.tanggal_masuk' => true,
            'containers.marking_id' => true,
            'containers.load_date' => true,
            '@table.container_barangs' => false,
            '@table.tracking_statuses' => false,
        ]);

        $this->assertNull(Marking::barangToMarkingWhereSql('markings'));
        $this->assertNull(Marking::codeColumn());

        $subquery = Marking::lastShippingAtSubquery('markings');
        $this->assertStringNotContainsString('markings.code', $subquery);
        $this->assertStringNotContainsString('b.marking = markings', $subquery);
        $this->assertStringContainsString('c.marking_id = markings.id', $subquery);
        $this->assertStringNotContainsString('UNION ALL', $subquery);
        $this->assertStringContainsString('NULLIF', $subquery);
        $this->assertStringContainsString('1970-01-01', $subquery);

        $this->assertStringNotContainsString('created_at', Marking::lastShippingAtSubquery('markings'));
    }

    public function test_period_filter_values_are_last_shipping_based(): void
    {
        $this->assertSame(['', 'today', 'yesterday', '7d', '30d', 'custom'], Marking::periodFilterValues());
    }

    public function test_resolve_period_bounds_rolling_from_today(): void
    {
        Carbon::setTestNow('2026-05-17');

        try {
            [$startToday, $endToday] = Marking::resolvePeriodBounds('today');
            $this->assertSame('2026-05-17', $startToday?->toDateString());
            $this->assertSame('2026-05-17', $endToday?->toDateString());

            [$startYesterday, $endYesterday] = Marking::resolvePeriodBounds('yesterday');
            $this->assertSame('2026-05-16', $startYesterday?->toDateString());
            $this->assertSame('2026-05-16', $endYesterday?->toDateString());

            [$start7, $end7] = Marking::resolvePeriodBounds('7d');
            $this->assertSame('2026-05-11', $start7?->toDateString());
            $this->assertSame('2026-05-17', $end7?->toDateString());

            [$start30, $end30] = Marking::resolvePeriodBounds('30d');
            $this->assertSame('2026-04-18', $start30?->toDateString());
            $this->assertSame('2026-05-17', $end30?->toDateString());

            $this->assertSame([null, null], Marking::resolvePeriodBounds(''));
            $this->assertSame([null, null], Marking::resolvePeriodBounds('custom', null, null));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_period_filter_uses_last_shipping_subquery_only(): void
    {
        $this->seedSchemaCache([
            'markings.code' => true,
            'markings.marking_code' => false,
            'barangs.marking_id' => true,
            'barangs.marking' => false,
            'barangs.tanggal_masuk' => true,
            'containers.marking_id' => false,
            'containers.load_date' => false,
            '@table.container_barangs' => false,
            '@table.containers' => false,
            '@table.tracking_statuses' => false,
        ]);

        $lastShip = Marking::lastShippingAtSubquery('markings');

        $this->assertStringNotContainsString('created_at', $lastShip);
        $this->assertStringContainsString('NULLIF', $lastShip);
        $this->assertStringNotContainsString("DATE({$lastShip}", $lastShip);
    }

    public function test_shipping_subquery_uses_greatest_with_correlated_barang_link(): void
    {
        $this->seedSchemaCache([
            'markings.code' => true,
            'markings.marking_code' => false,
            'barangs.marking_id' => true,
            'barangs.marking' => false,
            'barangs.tanggal_masuk' => true,
            'container_barangs.loaded_at' => true,
            'containers.load_date' => true,
            'containers.marking_id' => false,
            '@table.container_barangs' => true,
            '@table.containers' => true,
            '@table.tracking_statuses' => false,
        ]);

        $subquery = Marking::lastShippingAtSubquery('markings');
        $this->assertStringContainsString('GREATEST(', $subquery);
        $this->assertStringContainsString('b.marking_id = markings.id', $subquery);
        $this->assertStringContainsString('MAX(b.tanggal_masuk)', $subquery);
        $this->assertStringContainsString('MAX(DATE(cb.loaded_at))', $subquery);
        $this->assertStringContainsString('MAX(c.load_date)', $subquery);
        $this->assertStringNotContainsString('UNION ALL', $subquery);
        $this->assertStringNotContainsString('shipping_dates', $subquery);

        $selectFragment = Marking::lastShippingAtSubquery('markings') . ' AS last_shipping_at';
        $this->assertStringContainsString('markings.id', $selectFragment);
        $this->assertStringContainsString('AS last_shipping_at', $selectFragment);
    }

    public function test_order_by_code_uses_schema_code_column(): void
    {
        $this->seedSchemaCache([
            'markings.code' => true,
            'markings.marking_code' => false,
        ]);

        $this->assertSame('code', Marking::codeColumn());
    }

    public function test_shipping_subquery_returns_null_when_no_branches(): void
    {
        $this->seedSchemaCache([
            'markings.code' => false,
            'markings.marking_code' => false,
            'barangs.marking_id' => false,
            'barangs.marking' => false,
            'containers.marking_id' => false,
            '@table.container_barangs' => false,
            '@table.tracking_statuses' => false,
        ]);

        $this->assertSame('(SELECT NULL)', Marking::lastShippingAtSubquery('markings'));
    }
}
