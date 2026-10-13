<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SalesRevenueCalculator;
use Tests\TestCase;

final class SalesRevenuePaymentStatusTest extends TestCase
{
    public function test_enrich_row_payment_status_uses_own_invoice_paid(): void
    {
        $paid = SalesRevenueCalculator::enrichRowPaymentStatus([
            'own_invoice_paid' => true,
            'invoice_status' => 'unpaid',
        ]);
        $this->assertTrue($paid['is_paid']);
        $this->assertSame('paid', $paid['payment_status']);

        $byStatus = SalesRevenueCalculator::enrichRowPaymentStatus([
            'own_invoice_paid' => false,
            'invoice_status' => 'paid',
        ]);
        $this->assertTrue($byStatus['is_paid']);
        $this->assertSame('paid', $byStatus['payment_status']);

        $unpaid = SalesRevenueCalculator::enrichRowPaymentStatus([
            'own_invoice_paid' => false,
            'invoice_status' => 'unpaid',
        ]);
        $this->assertFalse($unpaid['is_paid']);
        $this->assertSame('unpaid', $unpaid['payment_status']);
    }

    public function test_enrich_row_payment_status_ignores_commission_payout_fields(): void
    {
        // Even if marking payout would be unpaid, invoice-paid drives the badge.
        $row = SalesRevenueCalculator::enrichRowPaymentStatus([
            'own_invoice_paid' => true,
            'invoice_status' => 'paid',
            'is_fixed' => true,
        ]);

        $this->assertTrue($row['is_paid']);
        $this->assertSame('paid', $row['payment_status']);
    }
}

