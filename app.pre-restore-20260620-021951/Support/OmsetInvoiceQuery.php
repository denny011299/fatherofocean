<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Omset period + payment helpers shared by web preview and Excel export.
 *
 * Includes both fully paid and unpaid invoices. Period is based on
 * tanggal lunas when paid, otherwise invoice created_at.
 */
final class OmsetInvoiceQuery
{
    public static function approvedPaidAtSubquery(): string
    {
        return "(SELECT MAX(COALESCE(payments.reviewed_at, payments.created_at))
            FROM payments
            WHERE payments.invoice_id = invoices.id
            AND payments.status = ?)";
    }

    public static function periodDateExpression(): string
    {
        $paidAt = self::approvedPaidAtSubquery();

        return "COALESCE({$paidAt}, invoices.created_at)";
    }

    public static function applyPeriodFilter(
        Builder $query,
        string $periodType,
        int $year,
        ?int $month = null,
        ?int $week = null,
    ): Builder {
        $periodDate = self::periodDateExpression();

        $query->whereRaw("YEAR({$periodDate}) = ?", ['approved', $year]);

        if ($periodType === 'mingguan') {
            if ($month !== null) {
                $query->whereRaw("MONTH({$periodDate}) = ?", ['approved', $month]);
            }
            if ($week !== null) {
                $paidAt = self::approvedPaidAtSubquery();
                $query->whereRaw("WEEK({$paidAt}, 1) = ?", ['approved', $week]);
            }
        } elseif ($periodType === 'bulanan' && $month !== null) {
            $query->whereRaw("MONTH({$periodDate}) = ?", ['approved', $month]);
        }

        return $query;
    }

    public static function omsetPeriodSelectSql(string $periodType): string
    {
        $periodDate = self::periodDateExpression();

        return match ($periodType) {
            'mingguan' => "CONCAT('Minggu ', WEEK({$periodDate}, 1))",
            'bulanan' => "DATE_FORMAT({$periodDate}, '%Y-%m')",
            'tahunan' => "YEAR({$periodDate})",
            default => "DATE_FORMAT({$periodDate}, '%Y-%m')",
        };
    }

    public static function paymentStatusLabel(Invoice $invoice): string
    {
        return Invoice::query()->whereKey($invoice->id)->fullyPaid()->exists()
            ? 'Sudah Bayar'
            : 'Belum Bayar';
    }
}
