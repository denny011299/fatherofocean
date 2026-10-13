<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FinanceInvoiceExportDataBuilder;
use PHPUnit\Framework\TestCase;

final class FinanceInvoiceExportDataBuilderTest extends TestCase
{
    public function test_shipment_totals_scale_to_grand_total_with_promo_gap(): void
    {
        $rows = FinanceInvoiceExportDataBuilder::allocateShipmentTotals([
            'A1' => 6000.0,
            'B1' => 4000.0,
        ], 9000.0);

        $this->assertCount(2, $rows);
        $this->assertSame('A1', $rows[0]['no_index']);
        $this->assertSame('B1', $rows[1]['no_index']);
        $this->assertEqualsWithDelta(5400.0, $rows[0]['total'], 0.01);
        $this->assertEqualsWithDelta(3600.0, $rows[1]['total'], 0.01);
        $this->assertEqualsWithDelta(9000.0, $rows[0]['total'] + $rows[1]['total'], 0.01);
    }

    public function test_rounding_remainder_lands_on_last_shipment(): void
    {
        $rows = FinanceInvoiceExportDataBuilder::allocateShipmentTotals([
            'A' => 1.0,
            'B' => 1.0,
            'C' => 1.0,
        ], 100.0);

        $this->assertEqualsWithDelta(33.33, $rows[0]['total'], 0.001);
        $this->assertEqualsWithDelta(33.33, $rows[1]['total'], 0.001);
        $this->assertEqualsWithDelta(33.34, $rows[2]['total'], 0.001);
        $this->assertEqualsWithDelta(100.0, $rows[0]['total'] + $rows[1]['total'] + $rows[2]['total'], 0.001);
    }
}
