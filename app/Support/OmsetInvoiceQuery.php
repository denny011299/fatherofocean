<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Omset period helpers shared by web preview and Excel export.
 *
 * Period is based on barang tanggal_masuk (the same date shown in the export).
 * Payment status labels still use approved payment timestamps.
 */
final class OmsetInvoiceQuery
{
    private const PAYMENT_STATUS_APPROVED = 'approved';

    public static function periodDateColumn(): string
    {
        return 'barangs.tanggal_masuk';
    }

    public static function approvedPaidAtSubquery(): string
    {
        // Inline status literal so fragments embedded in DB::raw() SELECT do not
        // introduce orphan ? placeholders that steal whereIn bindings.
        return "(SELECT MAX(COALESCE(payments.reviewed_at, payments.created_at))
            FROM payments
            WHERE payments.invoice_id = invoices.id
            AND payments.status = '".self::PAYMENT_STATUS_APPROVED."')";
    }

    public static function periodDateExpression(): string
    {
        return self::periodDateColumn();
    }

  /** @param Builder<Invoice> $query */
    public static function applyPeriodFilter(
        Builder $query,
        string $periodType,
        int $year,
        ?int $month = null,
        ?int $week = null,
    ): Builder {
        return $query->whereHas(
            'items.barang',
            static fn (Builder $barangQuery) => self::applyBarangPeriodFilter(
                $barangQuery,
                $periodType,
                $year,
                $month,
                $week,
            ),
        );
    }

    /** @param Builder<Barang> $query */
    public static function applyBarangPeriodFilter(
        Builder $query,
        string $periodType,
        int $year,
        ?int $month = null,
        ?int $week = null,
    ): Builder {
        $query->whereNotNull('tanggal_masuk');
        $query->whereYear('tanggal_masuk', $year);

        if ($periodType === 'mingguan') {
            if ($month !== null) {
                $query->whereMonth('tanggal_masuk', $month);
            }
            if ($week !== null) {
                $query->whereRaw('WEEK(tanggal_masuk, 1) = ?', [$week]);
            }
        } elseif ($periodType === 'bulanan' && $month !== null) {
            $query->whereMonth('tanggal_masuk', $month);
        }

        return $query;
    }

    public static function applyJoinedBarangPeriodFilter(
        QueryBuilder $query,
        string $periodType,
        int $year,
        ?int $month = null,
        ?int $week = null,
    ): QueryBuilder {
        $dateColumn = self::periodDateColumn();
        $query->whereNotNull($dateColumn);
        $query->whereRaw("YEAR({$dateColumn}) = ?", [$year]);

        if ($periodType === 'mingguan') {
            if ($month !== null) {
                $query->whereRaw("MONTH({$dateColumn}) = ?", [$month]);
            }
            if ($week !== null) {
                $query->whereRaw("WEEK({$dateColumn}, 1) = ?", [$week]);
            }
        } elseif ($periodType === 'bulanan' && $month !== null) {
            $query->whereRaw("MONTH({$dateColumn}) = ?", [$month]);
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
