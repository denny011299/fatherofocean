<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InvoiceDueResolver;
use App\Support\InvoiceStatusPresenter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class InvoiceStatusPresenterTest extends TestCase
{
    public function test_immediate_due_no_longer_forces_paid_status(): void
    {
        $resolved = [
            'due_date' => today(),
            'due_days' => 0,
            'is_immediate' => true,
        ];

        $this->assertSame('sent', InvoiceDueResolver::statusForResolved($resolved, 'sent'));
        $this->assertSame('draft', InvoiceDueResolver::statusForResolved($resolved, 'draft'));
    }

    public function test_due_date_tone_overdue_and_today(): void
    {
        $this->assertSame(
            InvoiceStatusPresenter::TONE_OVERDUE,
            InvoiceStatusPresenter::dueDateTone(Carbon::yesterday()),
        );
        $this->assertSame(
            InvoiceStatusPresenter::TONE_DUE_TODAY,
            InvoiceStatusPresenter::dueDateTone(today()),
        );
        $this->assertNull(InvoiceStatusPresenter::dueDateTone(today()->addDay()));
    }

    public function test_is_fully_paid_requires_actual_payment(): void
    {
        $this->assertTrue(InvoiceStatusPresenter::isFullyPaid(520_000, 0));
        $this->assertFalse(InvoiceStatusPresenter::isFullyPaid(0, 0));
        $this->assertFalse(InvoiceStatusPresenter::isFullyPaid(0, 520_000));
    }

    public function test_format_due_date_uses_spaced_month(): void
    {
        $formatted = InvoiceStatusPresenter::formatDueDate(Carbon::parse('2026-05-19'));

        $this->assertSame('19 May 2026', $formatted);
    }

    public function test_days_late_counts_from_due_date(): void
    {
        Carbon::setTestNow('2026-05-19');

        $this->assertSame(0, InvoiceStatusPresenter::daysLate(Carbon::parse('2026-05-19')));
        $this->assertSame(1, InvoiceStatusPresenter::daysLate(Carbon::parse('2026-05-18')));
        $this->assertSame(2, InvoiceStatusPresenter::daysLate(Carbon::parse('2026-05-17')));

        Carbon::setTestNow();
    }

    public function test_due_date_from_days_uses_bongkar_anchor(): void
    {
        Carbon::setTestNow('2026-07-14');

        $this->assertSame(
            '2026-07-14',
            InvoiceDueResolver::dueDateFromDays(0, Carbon::parse('2026-07-14'))->toDateString(),
        );
        $this->assertSame(
            '2026-07-21',
            InvoiceDueResolver::dueDateFromDays(7, Carbon::parse('2026-07-14'))->toDateString(),
        );
        $this->assertSame(
            '2026-07-15',
            InvoiceDueResolver::dueDateFromDays(1, Carbon::parse('2026-07-14'))->toDateString(),
        );

        Carbon::setTestNow();
    }

    public function test_format_due_date_null_is_dash(): void
    {
        $this->assertSame('—', InvoiceStatusPresenter::formatDueDate(null));
        $this->assertSame(0, InvoiceStatusPresenter::daysLate(null));
    }

    public function test_amount_column_classes_are_fixed(): void
    {
        $this->assertSame('invoice-amount-remaining', InvoiceStatusPresenter::remainingAmountClass(null, 0));
        $this->assertSame('invoice-amount-paid', InvoiceStatusPresenter::paidAmountClass());
    }
}
