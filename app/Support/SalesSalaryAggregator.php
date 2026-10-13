<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SalesCommission;
use App\Models\SalesSalaryPayout;
use App\Models\User;
use Illuminate\Support\Collection;

final class SalesSalaryAggregator
{
    /** @return list<string> */
    public static function settledStatuses(): array
    {
        return [
            SalesCommission::STATUS_FIXED,
            SalesCommission::STATUS_CONFIRMED,
            SalesCommission::STATUS_CAIR,
        ];
    }

    /**
     * Semua sales/supervisor untuk satu bulan; nominal mengikuti tab Komisi (filter tanggal_masuk).
     *
     * @return Collection<int, object{
     *     sales_id: int,
     *     period: string,
     *     item_count: int,
     *     total_amount: float,
     *     sales_name: string,
     *     is_settled: bool,
     *     proof_file: ?string,
     *     proof_url: ?string,
     *     proof_uploaded_at: ?string,
     *     admin_note: ?string,
     * }>
     */
    public static function monthlyPayoutMatrix(string $period, ?int $salesIdFilter = null): Collection
    {
        self::assertValidPeriod($period);

        $staffQuery = User::query()
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', ['sales', 'supervisor']))
            ->orderBy('name');

        if ($salesIdFilter !== null) {
            $staffQuery->where('id', $salesIdFilter);
        }

        $aggregates = self::payoutTotalsByStaffForPeriod($period, self::resolveScopedUser());

        $payouts = SalesSalaryPayout::query()
            ->where('period', $period)
            ->when($salesIdFilter !== null, static fn ($q) => $q->where('sales_id', $salesIdFilter))
            ->get()
            ->keyBy(static fn (SalesSalaryPayout $p) => $p->sales_id.'|'.$p->period);

        return $staffQuery->get(['id', 'name'])->map(static function (User $user) use ($period, $aggregates, $payouts) {
            $agg = $aggregates[$user->id] ?? ['item_count' => 0, 'total_amount' => 0.0];
            $itemCount = (int) $agg['item_count'];
            $total = round((float) $agg['total_amount'], 2);
            $key = $user->id.'|'.$period;
            /** @var SalesSalaryPayout|null $payout */
            $payout = $payouts->get($key);

            return (object) [
                'sales_id' => (int) $user->id,
                'period' => $period,
                'item_count' => $itemCount,
                'total_amount' => $total,
                'sales_name' => (string) $user->name,
                'is_settled' => $itemCount > 0,
                'proof_file' => $payout?->proof_file,
                'proof_url' => $payout?->proofUrl(),
                'proof_uploaded_at' => $payout?->proof_uploaded_at?->format('d/m/Y H:i'),
                'admin_note' => $payout?->admin_note,
            ];
        });
    }

    /** @return array{date_from: string, date_to: string} */
    public static function periodDateRange(string $period): array
    {
        self::assertValidPeriod($period);
        $start = $period.'-01';
        $end = date('Y-m-t', strtotime($start));

        return ['date_from' => $start, 'date_to' => $end];
    }

    /**
     * @return Collection<int, object{
     *     sales_id: int,
     *     period: string,
     *     item_count: int,
     *     total_amount: float,
     *     sales_name: string,
     *     proof_file: ?string,
     *     proof_url: ?string,
     *     proof_uploaded_at: ?string,
     *     admin_note: ?string,
     * }>
     */
    public static function monthlyPeriods(?int $salesIdFilter = null): Collection
    {
        $staffQuery = User::query()
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', ['sales', 'supervisor']))
            ->orderBy('name');

        if ($salesIdFilter !== null) {
            $staffQuery->where('id', $salesIdFilter);
        }

        $periods = self::discoverPayoutPeriods($salesIdFilter);

        $payouts = SalesSalaryPayout::query()
            ->when($salesIdFilter !== null, fn ($q) => $q->where('sales_id', $salesIdFilter))
            ->get()
            ->keyBy(static fn (SalesSalaryPayout $p) => $p->sales_id.'|'.$p->period);

        $rows = collect();

        foreach ($periods as $period) {
            $aggregates = self::payoutTotalsByStaffForPeriod($period, null);
            foreach ($staffQuery->get(['id', 'name']) as $user) {
                $agg = $aggregates[$user->id] ?? ['item_count' => 0, 'total_amount' => 0.0];
                if ((int) $agg['item_count'] === 0) {
                    continue;
                }

                $key = $user->id.'|'.$period;
                /** @var SalesSalaryPayout|null $payout */
                $payout = $payouts->get($key);

                $rows->push((object) [
                    'sales_id' => (int) $user->id,
                    'period' => $period,
                    'item_count' => (int) $agg['item_count'],
                    'total_amount' => round((float) $agg['total_amount'], 2),
                    'sales_name' => (string) $user->name,
                    'proof_file' => $payout?->proof_file,
                    'proof_url' => $payout?->proofUrl(),
                    'proof_uploaded_at' => $payout?->proof_uploaded_at?->format('d/m/Y H:i'),
                    'admin_note' => $payout?->admin_note,
                ]);
            }
        }

        return $rows->sortByDesc('period')->values();
    }

    /**
     * @return array{sales_id: int, period: string, items: list<array<string, mixed>>, total_amount: float}
     */
    public static function periodDetail(int $salesId, string $period): array
    {
        self::assertValidPeriod($period);

        $staff = User::query()->find($salesId);
        $items = self::payoutLineItemsForStaff($salesId, $period, $staff);

        $total = array_reduce(
            $items,
            static fn (float $sum, array $row) => $sum + (float) $row['confirmed_amount'],
            0.0,
        );

        return [
            'sales_id' => $salesId,
            'period' => $period,
            'items' => $items,
            'total_amount' => round($total, 2),
        ];
    }

    public static function resolveScopedSalesId(?User $viewer = null): ?int
    {
        $viewer ??= auth()->user();
        if ($viewer === null) {
            return null;
        }

        if ($viewer->hasAnyRole(['sales', 'supervisor'])) {
            return (int) $viewer->id;
        }

        return null;
    }

    public static function assertValidPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            abort(422, 'Invalid period.');
        }
    }

    public static function periodHasCommissions(int $salesId, string $period): bool
    {
        self::assertValidPeriod($period);

        $staff = User::query()->find($salesId);

        return self::payoutLineItemsForStaff($salesId, $period, $staff) !== [];
    }

    /**
     * Agregat pencairan per staff — sama dengan tab Komisi (tanggal_masuk + net fix).
     *
     * @return array<int, array{item_count: int, total_amount: float}>
     */
    private static function payoutTotalsByStaffForPeriod(string $period, ?User $scopedUser): array
    {
        $totals = [];

        foreach (self::commissionRowsForPeriod($period, $scopedUser) as $row) {
            if (! ($row['is_fixed'] ?? false)) {
                continue;
            }

            $salesId = (int) ($row['sales_id'] ?? 0);
            if ($salesId > 0) {
                $amount = (float) ($row['confirmed_amount'] ?? $row['estimated_amount'] ?? 0);
                self::bumpPayoutTotal($totals, $salesId, $amount);
            }

            $supervisorId = (int) ($row['supervisor_id'] ?? 0);
            if ($supervisorId > 0) {
                $supAmount = (float) ($row['supervisor_amount'] ?? 0);
                if ($supAmount > 0) {
                    self::bumpPayoutTotal($totals, $supervisorId, $supAmount);
                }
            }
        }

        foreach ($totals as &$entry) {
            $entry['total_amount'] = round((float) $entry['total_amount'], 2);
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payoutLineItemsForStaff(int $staffId, string $period, ?User $staff): array
    {
        $items = [];
        $supervisorOnly = $staff !== null
            && $staff->hasRole('supervisor')
            && ! $staff->hasRole('sales');

        foreach (self::commissionRowsForPeriod($period, self::scopedUserForStaff($staffId, $staff)) as $row) {
            if (! ($row['is_fixed'] ?? false)) {
                continue;
            }

            if ($supervisorOnly) {
                if ((int) ($row['supervisor_id'] ?? 0) !== $staffId) {
                    continue;
                }
                $amount = (float) ($row['supervisor_amount'] ?? 0);
            } else {
                if ((int) ($row['sales_id'] ?? 0) !== $staffId) {
                    continue;
                }
                $amount = (float) ($row['confirmed_amount'] ?? $row['estimated_amount'] ?? 0);
            }

            $items[] = [
                'id' => $row['commission_id'],
                'barang_id' => $row['barang_id'],
                'no_index' => $row['no_index'],
                'marking' => $row['marking'],
                'partai' => $row['partai'] ?? null,
                'confirmed_amount' => $amount,
                'status' => $row['status'],
                'confirmed_at' => $row['tanggal_masuk'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function commissionRowsForPeriod(string $period, ?User $scopedUser): array
    {
        $range = self::periodDateRange($period);

        return SalesRevenueCalculator::commissionRows(
            $scopedUser,
            null,
            $range['date_from'],
            $range['date_to'],
        );
    }

    /** @return list<string> */
    private static function discoverPayoutPeriods(?int $salesIdFilter): array
    {
        $query = \App\Models\Barang::query()
            ->whereNotNull('tanggal_masuk')
            ->selectRaw("DATE_FORMAT(tanggal_masuk, '%Y-%m') as period")
            ->distinct()
            ->orderByDesc('period');

        if ($salesIdFilter !== null) {
            $user = User::query()->find($salesIdFilter);
            SalesRevenueCalculator::applyCommissionBarangScope($query, $user);
        } else {
            SalesRevenueCalculator::applyCommissionBarangScope($query, null);
        }

        return $query->pluck('period')
            ->filter(static fn ($p) => is_string($p) && preg_match('/^\d{4}-\d{2}$/', $p))
            ->values()
            ->all();
    }

    private static function resolveScopedUser(): ?User
    {
        $id = self::resolveScopedSalesId();

        return $id !== null ? User::query()->find($id) : null;
    }

    private static function scopedUserForStaff(int $staffId, ?User $staff): ?User
    {
        $scoped = self::resolveScopedUser();
        if ($scoped !== null) {
            return $scoped;
        }

        if ($staff !== null && (int) $staff->id === $staffId) {
            return $staff->hasAnyRole(['sales', 'supervisor']) ? $staff : null;
        }

        return null;
    }

    /** @param  array<int, array{item_count: int, total_amount: float}>  $totals */
    private static function bumpPayoutTotal(array &$totals, int $userId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        if (! isset($totals[$userId])) {
            $totals[$userId] = ['item_count' => 0, 'total_amount' => 0.0];
        }

        $totals[$userId]['item_count']++;
        $totals[$userId]['total_amount'] += $amount;
    }
}
