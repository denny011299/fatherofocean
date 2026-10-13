<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\SalesIncentiveLine;
use App\Models\SalesIncentivePeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class SalesIncentiveService
{
    public static function schemaReady(): bool
    {
        return Schema::hasTable('sales_incentive_lines')
            && Schema::hasTable('sales_incentive_periods');
    }

    public static function syncForBarang(Barang $barang): void
    {
        if (! self::schemaReady() || ! SalesIncentiveCalculator::schemaReady()) {
            return;
        }

        $salesId = SalesRevenueCalculator::resolveSalesId($barang);
        if ($salesId === null || $salesId <= 0) {
            SalesIncentiveLine::query()->where('barang_id', $barang->id)->delete();

            return;
        }

        $existing = SalesIncentiveLine::query()->where('barang_id', $barang->id)->first();
        $period = self::resolveStuffingPeriod($barang);
        $cbm = CbmFormat::truncate((float) ($barang->total_cbm ?? 0));
        $paid = ShipmentEarningsSettlement::isBarangInvoicePaid((int) $barang->id);
        $pricingItemId = CompanyRevenueSettings::resolvePricingItemIdFromCbmType($barang->cbm_type);

        SalesIncentiveLine::query()->updateOrCreate(
            ['barang_id' => $barang->id],
            [
                'sales_id' => $salesId,
                'pricing_item_id' => $pricingItemId,
                'period' => $period,
                'total_cbm' => $cbm,
                'paid_cbm' => $paid ? $cbm : 0,
                'stuffed_at' => self::resolveStuffingAt($barang),
                'invoice_paid_at' => $paid
                    ? ($existing?->invoice_paid_at ?? now())
                    : null,
            ],
        );

        self::rebuildPeriodAggregate($salesId, $period);
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function monthlyMatrix(string $period, ?User $user = null): Collection
    {
        if (! self::schemaReady()) {
            return collect();
        }

        $query = SalesIncentivePeriod::query()
            ->with('sales:id,name')
            ->where('period', $period);

        $scopedId = self::resolveScopedSalesId($user);
        if ($scopedId !== null) {
            $query->where('sales_id', $scopedId);
        }

        return $query->orderBy('sales_id')->get()->map(static function (SalesIncentivePeriod $row): array {
            $estimasi = (float) $row->estimasi_amount;
            $cair = (float) $row->cair_amount;
            $pending = max(0, $estimasi - $cair);

            return [
                'sales_id' => (int) $row->sales_id,
                'sales_name' => (string) ($row->sales?->name ?? '-'),
                'period' => (string) $row->period,
                'item_count' => (int) SalesIncentiveLine::query()
                    ->where('sales_id', $row->sales_id)
                    ->where('period', $row->period)
                    ->count(),
                'cbm_stuffing' => (float) $row->cbm_stuffing,
                'cbm_paid' => (float) $row->cbm_paid,
                'estimasi_amount' => $estimasi,
                'cair_amount' => $cair,
                'pending_amount' => $pending,
                'payout_status' => self::payoutStatus($estimasi, $cair, (float) $row->cbm_stuffing),
            ];
        });
    }

    /** @return array{estimasi: float, period: string} */
    public static function periodSummary(string $period, ?User $user = null): array
    {
        $rows = self::monthlyMatrix($period, $user);

        return [
            'estimasi' => (float) $rows->sum('estimasi_amount'),
            'period' => $period,
        ];
    }

    /** @return array<string, mixed> */
    public static function periodDetail(int $salesId, string $period): array
    {
        if (! self::schemaReady()) {
            return [
                'cbm_stuffing' => 0,
                'cbm_paid' => 0,
                'estimasi_amount' => 0,
                'cair_amount' => 0,
                'pending_amount' => 0,
                'full_blocks_stuffing' => 0,
                'full_blocks_paid' => 0,
                'items' => [],
            ];
        }

        $aggregate = SalesIncentivePeriod::query()
            ->where('sales_id', $salesId)
            ->where('period', $period)
            ->first();

        $lineRows = SalesIncentiveLine::query()
            ->where('sales_id', $salesId)
            ->where('period', $period)
            ->with(['barang:id,no_index,marking,status', 'pricingItem:id,name'])
            ->orderBy('stuffed_at')
            ->get();

        $stuffingGroups = self::groupCbmByPricingItem($lineRows, 'total_cbm');
        $paidGroups = self::groupCbmByPricingItem($lineRows, 'paid_cbm');

        $fullBlocksStuffing = 0;
        foreach ($stuffingGroups as $pricingItemId => $cbm) {
            $fullBlocksStuffing += SalesIncentiveCalculator::fullBlocksForCbm($cbm, $pricingItemId);
        }

        $fullBlocksPaid = 0;
        foreach ($paidGroups as $pricingItemId => $cbm) {
            $fullBlocksPaid += SalesIncentiveCalculator::fullBlocksForCbm($cbm, $pricingItemId);
        }

        $estimasi = (float) ($aggregate?->estimasi_amount ?? 0);
        $cair = (float) ($aggregate?->cair_amount ?? 0);

        $items = $lineRows
            ->map(static fn (SalesIncentiveLine $line) => [
                'id' => $line->id,
                'barang_id' => $line->barang_id,
                'no_index' => $line->barang?->no_index,
                'marking' => $line->barang?->marking,
                'pricing_item_id' => $line->pricing_item_id,
                'pricing_item_name' => $line->pricingItem?->name,
                'total_cbm' => (float) $line->total_cbm,
                'paid_cbm' => (float) $line->paid_cbm,
                'is_paid' => (float) $line->paid_cbm > 0,
                'stuffed_at' => $line->stuffed_at?->format('d/m/Y H:i'),
                'invoice_paid_at' => $line->invoice_paid_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();

        return [
            'cbm_stuffing' => (float) ($aggregate?->cbm_stuffing ?? 0),
            'cbm_paid' => (float) ($aggregate?->cbm_paid ?? 0),
            'estimasi_amount' => $estimasi,
            'cair_amount' => $cair,
            'pending_amount' => max(0, $estimasi - $cair),
            'full_blocks_stuffing' => $fullBlocksStuffing,
            'full_blocks_paid' => $fullBlocksPaid,
            'items' => $items,
        ];
    }

    private static function rebuildPeriodAggregate(int $salesId, string $period): void
    {
        $lineRows = SalesIncentiveLine::query()
            ->where('sales_id', $salesId)
            ->where('period', $period)
            ->get(['pricing_item_id', 'total_cbm', 'paid_cbm']);

        if ($lineRows->isEmpty()) {
            SalesIncentivePeriod::query()
                ->where('sales_id', $salesId)
                ->where('period', $period)
                ->delete();

            return;
        }

        $cbmStuffing = CbmFormat::truncate((float) $lineRows->sum('total_cbm'));
        $cbmPaid = CbmFormat::truncate((float) $lineRows->sum('paid_cbm'));
        $stuffingGroups = self::groupCbmByPricingItem($lineRows, 'total_cbm');
        $paidGroups = self::groupCbmByPricingItem($lineRows, 'paid_cbm');

        $estimasi = 0.0;
        foreach ($stuffingGroups as $pricingItemId => $cbm) {
            $estimasi += SalesIncentiveCalculator::amountForCbm($cbm, $pricingItemId);
        }

        $cair = 0.0;
        foreach ($paidGroups as $pricingItemId => $cbm) {
            $cair += SalesIncentiveCalculator::amountForCbm($cbm, $pricingItemId);
        }

        SalesIncentivePeriod::query()->updateOrCreate(
            ['sales_id' => $salesId, 'period' => $period],
            [
                'cbm_stuffing' => $cbmStuffing,
                'cbm_paid' => $cbmPaid,
                'estimasi_amount' => round($estimasi, 2),
                'cair_amount' => round($cair, 2),
            ],
        );
    }

    /**
     * @param  Collection<int, SalesIncentiveLine>  $lines
     * @return array<int, float>
     */
    private static function groupCbmByPricingItem(Collection $lines, string $field): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $pricingItemId = (int) ($line->pricing_item_id ?? 0);
            if ($pricingItemId <= 0) {
                continue;
            }

            $groups[$pricingItemId] = ($groups[$pricingItemId] ?? 0)
                + CbmFormat::truncate((float) ($line->{$field} ?? 0));
        }

        return $groups;
    }

    private static function resolveStuffingPeriod(Barang $barang): string
    {
        $at = self::resolveStuffingAt($barang) ?? $barang->tanggal_masuk ?? now();

        return Carbon::parse($at)->format('Y-m');
    }

    private static function resolveStuffingAt(Barang $barang): ?Carbon
    {
        $loadedAt = $barang->containers()->latest('container_barang.loaded_at')->value('container_barang.loaded_at');
        if ($loadedAt !== null) {
            return Carbon::parse($loadedAt);
        }

        if ($barang->status === Barang::STATUS_STUFFING && $barang->updated_at !== null) {
            return Carbon::parse($barang->updated_at);
        }

        return $barang->tanggal_masuk !== null ? Carbon::parse($barang->tanggal_masuk) : null;
    }

    private static function payoutStatus(float $estimasi, float $cair, float $cbmStuffing): string
    {
        if ($cbmStuffing <= 0) {
            return 'empty';
        }

        if ($cair <= 0) {
            return 'estimasi';
        }

        if ($cair + 0.009 < $estimasi) {
            return 'partial';
        }

        return 'lunas';
    }

    private static function resolveScopedSalesId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($user->hasRole('sales') && ! $user->hasAnyRole(['super_admin', 'supervisor', 'finance', 'admin_cs'])) {
            return (int) $user->id;
        }

        return null;
    }
}
