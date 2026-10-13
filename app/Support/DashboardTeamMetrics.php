<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\SalesRevenueCalculator;

/** Omset & CBM dashboard untuk sales / supervisor (marking sendiri + tim). */
final class DashboardTeamMetrics
{
    /** Omset ongkos kirim berdasarkan tanggal_masuk barang (sama logika periode dengan Total CBM). */
    public static function paidOmset(?User $scopedUser, ?string $dateFrom, ?string $dateTo): float
    {
        $total = 0.0;

        foreach (self::barangsInPeriod($scopedUser, $dateFrom, $dateTo)->cursor() as $barang) {
            $total += SalesRevenueCalculator::billableBase($barang);
        }

        return round($total, 0);
    }

    /**
     * Penghasilan dashboard periode:
     * - Sales/marketing: komisi % dari omset pengiriman (mis. 2,5%).
     * - PIC/supervisor: capital gain (markup setelah dipotong komisi sales).
     */
    public static function commissionTotal(?User $scopedUser, ?string $dateFrom, ?string $dateTo): float
    {
        if ($scopedUser === null) {
            return 0.0;
        }

        $totals = SalesRevenueCalculator::totalsForUser($scopedUser, $dateFrom, $dateTo);

        if ($scopedUser->hasRole('supervisor')) {
            if ($scopedUser->isSupervisorOnly()) {
                return round((float) ($totals['estimasi'] ?? 0) + (float) ($totals['fix'] ?? 0), 0);
            }

            return round(
                (float) ($totals['estimasi_supervisor'] ?? 0) + (float) ($totals['fix_supervisor'] ?? 0),
                0,
            );
        }

        return round((float) ($totals['estimasi'] ?? 0) + (float) ($totals['fix'] ?? 0), 0);
    }

    /** CBM dari barang masuk gudang (tanggal_masuk), tidak bergantung invoice lunas. */
    public static function totalCbm(
        ?User $scopedUser,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $salesId = null,
    ): float {
        $query = self::barangsInPeriod($scopedUser, $dateFrom, $dateTo, $salesId);

        return CbmFormat::truncate((float) $query->sum('total_cbm'));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Barang> */
    private static function barangsInPeriod(
        ?User $scopedUser,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $salesId = null,
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Barang::query();

        if ($dateFrom !== null && $dateTo !== null) {
            $query->whereDate('tanggal_masuk', '>=', $dateFrom)
                ->whereDate('tanggal_masuk', '<=', $dateTo);
        }

        if ($scopedUser !== null) {
            Marking::restrictBarangsQuery($query, $scopedUser);
        } elseif ($salesId !== null && $salesId > 0) {
            self::restrictBarangsToSalesId($query, $salesId);
        }

        return $query;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Barang>  $barangQuery */
    private static function restrictBarangsToSalesId(
        \Illuminate\Database\Eloquent\Builder $barangQuery,
        int $salesId,
    ): void {
        $markingQuery = Marking::query()->where('sales_id', $salesId);

        if (Marking::hasColumnCached('barangs', 'marking_id')) {
            $barangQuery->whereIn('marking_id', $markingQuery->select('id'));

            return;
        }

        if ($col = Marking::codeColumn()) {
            $barangQuery->whereIn('marking', $markingQuery->select($col));

            return;
        }

        $barangQuery->whereRaw('0 = 1');
    }
}
