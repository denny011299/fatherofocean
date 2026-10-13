<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SalesMarkingCommissionDetail;
use Tests\TestCase;

final class SalesMarkingCommissionDetailTest extends TestCase
{
    public function test_commission_totals_by_sales_marking_sums_fixed_rows_only(): void
    {
        $rows = [
            [
                'sales_id' => 7,
                'marking' => 'ABC01',
                'is_fixed' => true,
                'confirmed_amount' => 1000,
            ],
            [
                'sales_id' => 7,
                'marking' => 'abc01',
                'is_fixed' => true,
                'estimated_amount' => 250.5,
            ],
            [
                'sales_id' => 7,
                'marking' => 'ABC01',
                'is_fixed' => false,
                'estimated_amount' => 999,
            ],
            [
                'sales_id' => 8,
                'marking' => 'ABC01',
                'is_fixed' => true,
                'confirmed_amount' => 50,
            ],
        ];

        $totals = SalesMarkingCommissionDetail::commissionTotalsBySalesMarking($rows);

        $this->assertSame(1250.5, $totals['7|abc01']);
        $this->assertSame(50.0, $totals['8|abc01']);
        $this->assertArrayNotHasKey('7|ABC01', $totals);
    }

    public function test_is_marking_paid_compares_owed_against_paid_totals(): void
    {
        $rows = [
            [
                'sales_id' => 3,
                'marking' => 'MARK1',
                'is_fixed' => true,
                'sales_commission_base' => 20_000,
                'commission_rate' => 2.5,
                'confirmed_amount' => 500,
            ],
        ];

        // owed = round(20000 * 2.5 / 100) = 500
        $this->assertFalse(SalesMarkingCommissionDetail::isMarkingPaid(3, 'MARK1', $rows, [
            '3|mark1' => 499.99,
        ]));
        $this->assertTrue(SalesMarkingCommissionDetail::isMarkingPaid(3, 'MARK1', $rows, [
            '3|mark1' => 500,
        ]));
        $this->assertFalse(SalesMarkingCommissionDetail::isMarkingPaid(3, 'MARK1', $rows, []));
    }

    public function test_owed_matches_detail_omset_times_rate_not_sum_of_line_komisi(): void
    {
        // Same case as SONDAKH: sum(line komisi)=138416 but round(omset×rate)=138415
        $rows = [
            [
                'sales_id' => 14,
                'marking' => 'EK/ON/SONDAKH',
                'is_fixed' => true,
                'sales_commission_base' => 2_429_900,
                'commission_rate' => 2.5,
                'confirmed_amount' => 60_748,
            ],
            [
                'sales_id' => 14,
                'marking' => 'EK/ON/SONDAKH',
                'is_fixed' => true,
                'sales_commission_base' => 3_106_700,
                'commission_rate' => 2.5,
                'confirmed_amount' => 77_668,
            ],
        ];

        $owed = SalesMarkingCommissionDetail::owedTotalsBySalesMarking($rows);
        $this->assertSame(138_415.0, $owed['14|ek/on/sondakh']);
        $this->assertTrue(SalesMarkingCommissionDetail::isMarkingPaid(14, 'EK/ON/SONDAKH', $rows, [
            '14|ek/on/sondakh' => 138_415,
        ]));

        $enriched = SalesMarkingCommissionDetail::enrichListPaymentStatuses($rows);
        // No DB payouts in unit test — unpaid without paidTotals table; assert owed path via isMarkingPaid above.
        $this->assertFalse($enriched[0]['commission_paid']);
    }

    public function test_enrich_list_payment_statuses_sets_invoice_and_commission_fields(): void
    {
        $rows = SalesMarkingCommissionDetail::enrichListPaymentStatuses([
            [
                'sales_id' => 3,
                'marking' => 'MARK1',
                'is_fixed' => true,
                'sales_commission_base' => 20_000,
                'commission_rate' => 2.5,
                'confirmed_amount' => 500,
                'own_invoice_paid' => true,
                'invoice_status' => 'unpaid',
            ],
            [
                'sales_id' => 3,
                'marking' => 'MARK1',
                'is_fixed' => true,
                'sales_commission_base' => 0,
                'commission_rate' => 2.5,
                'confirmed_amount' => 0,
                'own_invoice_paid' => false,
                'invoice_status' => 'unpaid',
            ],
        ]);

        $this->assertTrue($rows[0]['is_paid']);
        $this->assertSame('paid', $rows[0]['payment_status']);
        $this->assertFalse($rows[0]['commission_paid']);
        $this->assertSame('unpaid', $rows[0]['commission_status']);
        $this->assertSame('unpaid', $rows[0]['commission_payment_status']);

        $this->assertFalse($rows[1]['is_paid']);
        $this->assertSame('unpaid', $rows[1]['payment_status']);
        $this->assertFalse($rows[1]['commission_paid']);
        $this->assertSame('unpaid', $rows[1]['commission_status']);
    }

    public function test_enrich_row_payment_status_still_invoice_driven(): void
    {
        $paid = \App\Support\SalesRevenueCalculator::enrichRowPaymentStatus([
            'own_invoice_paid' => true,
            'invoice_status' => 'unpaid',
        ]);
        $this->assertTrue($paid['is_paid']);
        $this->assertSame('paid', $paid['payment_status']);
    }

    public function test_invoice_status_from_flags_all_paid(): void
    {
        $this->assertSame('paid', SalesMarkingCommissionDetail::invoiceStatusFromFlags([true, true]));
    }

    public function test_invoice_status_from_flags_none_paid(): void
    {
        $this->assertSame('unpaid', SalesMarkingCommissionDetail::invoiceStatusFromFlags([]));
        $this->assertSame('unpaid', SalesMarkingCommissionDetail::invoiceStatusFromFlags([false, false]));
    }

    public function test_invoice_status_from_flags_mixed_is_partial(): void
    {
        // Opening a paid table row whose marking still has unpaid siblings → Sebagian, not Belum.
        $this->assertSame('partial', SalesMarkingCommissionDetail::invoiceStatusFromFlags([true, false]));
        $this->assertSame('partial', SalesMarkingCommissionDetail::invoiceStatusFromFlags([false, true, true]));
    }
}
