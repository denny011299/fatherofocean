<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\SalesIncentiveLine;
use App\Models\SalesIncentivePeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        if ($salesId === null) {
            self::removeLine($barang->id);

            return;
        }

        if ($barang->status !== Barang::STATUS_STUFFING) {
            return;
        }

        $existing = SalesIncentiveLine::query()->where('barang_id', $barang->id)->first();
        $oldPeriod = $existing?->period;

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

        self::recalcPeriod($salesId, $period);

        if ($oldPeriod !== null && $oldPeriod !== $period) {
            self::recalcPeriod($salesId, $oldPeriod);
        }
    }

    public static function recalcPeriod(int $salesId, string $period): void
    {
        if (! self::schemaReady()) {
            return;
        }

        SalesSalaryAggregator::assertValidPeriod($period);

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

        SalesIncentivePeriod::query()->updateOrCreate(
            ['sales_id' => $salesId, 'period' => $period],
            [
                'cbm_stuffing' => $cbmStuffing,
                'cbm_paid' => $cbmPaid,
                'estimasi_amount' => SalesIncentiveCalculator::totalAmountForCategoryGroups($stuffingGroups),
                'cair_amount' => SalesIncentiveCalculator::totalAmountForCategoryGroups($paidGroups),
            ],
        );
    }

    private static function removeLine(int $barangId): void
    {
        $line = SalesIncentiveLine::query()->where('barang_id', $barangId)->first();
        if ($line === null) {
            return;
        }

        $salesId = (int) $line->sales_id;
        $period = (string) $line->period;
        $line->delete();
        self::recalcPeriod($salesId, $period);
    }

    public static function resolveStuffingPeriod(Barang $barang): string
    {
        return self::resolveStuffingAt($barang)->format('Y-m');
    }

    public static function resolveStuffingAt(Barang $barang): Carbon
    {
        $trackingAt = DB::table('tracking_statuses')
            ->where('barang_id', $barang->id)
            ->where('status', Barang::STATUS_STUFFING)
            ->orderBy('created_at')
            ->value('created_at');

        if ($trackingAt !== null) {
            return Carbon::parse($trackingAt);
        }

        if ($barang->tanggal_masuk !== null) {
            return Carbon::parse($barang->tanggal_masuk)->startOfDay();
        }

        return $barang->updated_at ?? now();
    }

    /**
     * @return Collection<int, object{
     *     sales_id: int,
     *     period: string,
     *     sales_name: string,
     *     item_count: int,
     *     cbm_stuffing: float,
     *     cbm_paid: float,
     *     estimasi_amount: float,
     *     cair_amount: float,
     *     pending_amount: float,
     *     payout_status: string,
     * }>
     */
    public static function monthlyMatrix(string $period, ?int $salesIdFilter = null): Collection
    {
        SalesSalaryAggregator::assertValidPeriod($period);

        $staffQuery = User::query()
            ->role('sales')
            ->orderBy('name');

        if ($salesIdFilter !== null) {
            $staffQuery->where('id', $salesIdFilter);
        }

        $periodRows = SalesIncentivePeriod::query()
            ->where('period', $period)
            ->when($salesIdFilter !== null, static fn ($q) => $q->where('sales_id', $salesIdFilter))
            ->get()
            ->keyBy(static fn (SalesIncentivePeriod $p) => $p->sales_id);

        $lineCounts = DB::table('sales_incentive_lines')
            ->where('period', $period)
            ->when($salesIdFilter !== null, static fn ($q) => $q->where('sales_id', $salesIdFilter))
            ->groupBy('sales_id')
            ->select(['sales_id', DB::raw('COUNT(*) as item_count')])
            ->pluck('item_count', 'sales_id');

        return $staffQuery->get(['id', 'name'])->map(static function (User $user) use ($period, $periodRows, $lineCounts) {
            /** @var SalesIncentivePeriod|null $row */
            $row = $periodRows->get($user->id);
            $estimasi = round((float) ($row?->estimasi_amount ?? 0), 2);
            $cair = round((float) ($row?->cair_amount ?? 0), 2);
            $pending = round(max(0, $estimasi - $cair), 2);

            return (object) [
                'sales_id' => (int) $user->id,
                'period' => $period,
                'sales_name' => (string) $user->name,
                'item_count' => (int) ($lineCounts[$user->id] ?? 0),
                'cbm_stuffing' => CbmFormat::truncate((float) ($row?->cbm_stuffing ?? 0)),
                'cbm_paid' => CbmFormat::truncate((float) ($row?->cbm_paid ?? 0)),
                'estimasi_amount' => $estimasi,
                'cair_amount' => $cair,
                'pending_amount' => $pending,
                'payout_status' => self::payoutStatusLabel($estimasi, $cair),
            ];
        });
    }

    /**
     * @return array{period: string, estimasi: float, cair: float, pending: float}
     */
    public static function summaryTotals(string $period, ?int $salesIdFilter = null): array
    {
        if (! self::schemaReady()) {
            return [
                'period' => $period,
                'estimasi' => 0.0,
                'cair' => 0.0,
                'pending' => 0.0,
            ];
        }

        SalesSalaryAggregator::assertValidPeriod($period);

        $rows = self::monthlyMatrix($period, $salesIdFilter);

        $estimasi = round((float) $rows->sum('estimasi_amount'), 2);
        $cair = round((float) $rows->sum('cair_amount'), 2);

        return [
            'period' => $period,
            'estimasi' => $estimasi,
            'cair' => $cair,
            'pending' => round(max(0, $estimasi - $cair), 2),
        ];
    }

    private static function payoutStatusLabel(float $estimasi, float $cair): string
    {
        if ($estimasi <= 0) {
            return 'empty';
        }

        if ($cair <= 0) {
            return 'estimasi';
        }

        if ($cair >= $estimasi) {
            return 'lunas';
        }

        return 'partial';
    }

    /**
     * @return array<string, mixed>
     */
    public static function periodDetail(int $salesId, string $period): array
    {
        SalesSalaryAggregator::assertValidPeriod($period);

        $summary = SalesIncentivePeriod::query()
            ->where('sales_id', $salesId)
            ->where('period', $period)
            ->first();

        $lineRows = SalesIncentiveLine::query()
            ->where('sales_id', $salesId)
            ->where('period', $period)
            ->with(['barang:id,no_index,marking,status', 'pricingItem:id,name'])
            ->orderBy('stuffed_at')
            ->get();

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
                'stuffed_at' => $line->stuffed_at?->format('d/m/Y'),
                'invoice_paid_at' => $line->invoice_paid_at?->format('d/m/Y H:i'),
            ])
            ->all();

        $estimasi = round((float) ($summary?->estimasi_amount ?? 0), 2);
        $cair = round((float) ($summary?->cair_amount ?? 0), 2);
        $stuffingGroups = self::groupCbmByPricingItem($lineRows, 'total_cbm');
        $paidGroups = self::groupCbmByPricingItem($lineRows, 'paid_cbm');

        return [
            'sales_id' => $salesId,
            'period' => $period,
            'cbm_stuffing' => CbmFormat::truncate((float) ($summary?->cbm_stuffing ?? 0)),
            'cbm_paid' => CbmFormat::truncate((float) ($summary?->cbm_paid ?? 0)),
            'estimasi_amount' => $estimasi,
            'cair_amount' => $cair,
            'pending_amount' => round(max(0, $estimasi - $cair), 2),
            'full_blocks_stuffing' => SalesIncentiveCalculator::totalFullBlocksForCategoryGroups($stuffingGroups),
            'full_blocks_paid' => SalesIncentiveCalculator::totalFullBlocksForCategoryGroups($paidGroups),
            'items' => $items,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesIncentiveLine>  $lines
     * @return list<object{pricing_item_id: ?int, cbm: float}>
     */
    private static function groupCbmByPricingItem(\Illuminate\Support\Collection $lines, string $cbmField): array
    {
        return $lines
            ->groupBy(static fn (SalesIncentiveLine $line) => (int) ($line->pricing_item_id ?? 0))
            ->map(static function (\Illuminate\Support\Collection $group, int|string $pricingItemId) use ($cbmField): object {
                $id = (int) $pricingItemId;

                return (object) [
                    'pricing_item_id' => $id > 0 ? $id : null,
                    'cbm' => CbmFormat::truncate((float) $group->sum($cbmField)),
                ];
            })
            ->values()
            ->all();
    }
}
