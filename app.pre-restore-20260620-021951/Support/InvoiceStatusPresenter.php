<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

final class InvoiceStatusPresenter
{
    public const TONE_PAID = 'paid';

    public const TONE_WAITING = 'waiting';

    public const TONE_OVERDUE = 'overdue';

    public const TONE_DUE_TODAY = 'due_today';

    public const TONE_DEFAULT = 'default';

    /**
     * @return array{key: string, label: string, class: string, tone: string}
     */
    public static function forInvoice(
        Invoice $invoice,
        ?float $totalPaid = null,
        ?float $remaining = null,
        ?int $pendingPayments = null,
    ): array {
        $grandTotal = (float) $invoice->grand_total;
        $paid = $totalPaid ?? (float) $invoice->payments
            ->where('status', 'approved')
            ->sum('amount');
        $left = $remaining ?? max(0, $grandTotal - $paid);
        $pending = $pendingPayments ?? (int) $invoice->payments
            ->where('status', 'pending')
            ->count();

        if (self::isFullyPaid($paid, $left)) {
            return self::make(self::TONE_PAID, __('finance.status_paid'), 'badge-status-success');
        }

        if ($pending > 0) {
            return self::make(
                'pending_review',
                __('finance.status_waiting_verification'),
                'badge-status-info',
                self::TONE_WAITING,
            );
        }

        $dueTone = self::dueDateTone($invoice->due_date);
        if ($dueTone === self::TONE_OVERDUE || $dueTone === self::TONE_DUE_TODAY) {
            return self::make(
                'late',
                __('finance.status_late_days', ['days' => self::daysLate($invoice->due_date)]),
                'badge-status-danger',
                self::TONE_OVERDUE,
            );
        }

        if (in_array($invoice->status, ['draft', 'sent'], true)) {
            return self::make(self::TONE_WAITING, __('finance.status_waiting_payment'), 'badge-status-info');
        }

        return self::make(
            $invoice->status,
            __('finance.status_' . $invoice->status, ucfirst($invoice->status)),
            'badge-status-' . $invoice->status,
            self::TONE_DEFAULT,
        );
    }

    public static function isFullyPaid(float $totalPaid, float $remaining): bool
    {
        return $remaining <= 0.01 && $totalPaid > 0.01;
    }

    public static function formatDueDate(?Carbon $dueDate): string
    {
        return $dueDate?->format('j M Y') ?? '—';
    }

    public static function daysLate(?Carbon $dueDate): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $due = $dueDate->copy()->startOfDay();
        $today = today()->startOfDay();

        if ($due->gt($today)) {
            return 0;
        }

        return (int) $due->diffInDays($today);
    }

    public static function dueDateTone(?Carbon $dueDate): ?string
    {
        if ($dueDate === null) {
            return null;
        }

        $due = $dueDate->copy()->startOfDay();
        $today = today()->startOfDay();

        if ($due->lt($today)) {
            return self::TONE_OVERDUE;
        }

        if ($due->equalTo($today)) {
            return self::TONE_DUE_TODAY;
        }

        return null;
    }

    public static function dueDateCellClass(?Carbon $dueDate): string
    {
        $tone = self::dueDateTone($dueDate);

        return match ($tone) {
            self::TONE_OVERDUE, self::TONE_DUE_TODAY => 'invoice-due-urgent',
            default => '',
        };
    }

    public static function remainingAmountClass(?Carbon $dueDate, float $remaining): string
    {
        return 'invoice-amount-remaining';
    }

    public static function paidAmountClass(): string
    {
        return 'invoice-amount-paid';
    }

    /**
     * @return array{key: string, label: string, class: string, tone: string}
     */
    private static function make(
        string $key,
        string $label,
        string $class,
        ?string $tone = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'class' => 'badge-glass ' . $class,
            'tone' => $tone ?? $key,
        ];
    }
}
