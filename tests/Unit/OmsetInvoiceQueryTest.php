<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OmsetInvoiceQuery;
use PHPUnit\Framework\TestCase;

final class OmsetInvoiceQueryTest extends TestCase
{
    public function test_period_sql_fragments_do_not_use_unbound_placeholders(): void
    {
        $this->assertStringNotContainsString('?', OmsetInvoiceQuery::approvedPaidAtSubquery());
        $this->assertStringNotContainsString('?', OmsetInvoiceQuery::periodDateExpression());

        foreach (['mingguan', 'bulanan', 'tahunan'] as $periodType) {
            $this->assertStringNotContainsString(
                '?',
                OmsetInvoiceQuery::omsetPeriodSelectSql($periodType),
                "Period select SQL for {$periodType} must not contain orphan placeholders.",
            );
        }
    }

    public function test_period_date_uses_barang_tanggal_masuk(): void
    {
        $this->assertSame('barangs.tanggal_masuk', OmsetInvoiceQuery::periodDateExpression());
        $this->assertSame('barangs.tanggal_masuk', OmsetInvoiceQuery::periodDateColumn());
    }

    public function test_approved_paid_at_subquery_filters_by_approved_status(): void
    {
        $this->assertStringContainsString(
            "payments.status = 'approved'",
            OmsetInvoiceQuery::approvedPaidAtSubquery(),
        );
    }
}
