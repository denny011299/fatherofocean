<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SalesRevenueCalculator
{
    /** @deprecated Use {@see SalesCommissionSettings::defaultRate()} */
    public const RATE_PERCENT = 2.5;

    private static function roundIdr(float $value): float
    {
        return round($value, 0);
    }

    /**
     * Dasar komisi = subtotal pengiriman (CBM/berat), bukan line_total invoice penuh.
     * Biaya additional (barang_additional_prices) tidak masuk invoice line — otomatis terluar.
     */
    public static function billableBase(Barang $barang): float
    {
        $item = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoice_items.barang_id', $barang->id)
            ->whereNotIn('invoices.status', ['cancelled'])
            ->orderByDesc('invoice_items.id')
            ->first(['invoice_items.line_total', 'invoice_items.insurance', 'invoice_items.extra']);

        if ($item !== null) {
            $shippingOnly = (float) $item->line_total
                - (float) $item->insurance
                - (float) $item->extra;

            if ($shippingOnly > 0) {
                return self::roundIdr(max(0, $shippingOnly));
            }
        }

        return self::roundIdr(BarangShippingPricing::shippingSubtotalIdr($barang));
    }

    public static function estimatedAmount(Barang $barang, ?float $ratePercent = null, ?int $salesId = null): float
    {
        $breakdown = self::earningsBreakdown($barang, $ratePercent, $salesId);

        return $breakdown !== null ? $breakdown['sales_commission'] : 0.0;
    }

    public static function companyProfitForOmset(float $omset): float
    {
        if ($omset <= 0) {
            return 0.0;
        }

        $fixed = CompanyRevenueSettings::fixedAmount();

        return self::roundIdr(min($omset, $fixed));
    }

    /** Kas admin per pengiriman — pakai snapshot barang jika ada, else pengaturan saat ini. */
    public static function companyProfitForBarang(Barang $barang, float $omset): float
    {
        if ($omset <= 0) {
            return 0.0;
        }

        $fixed = self::resolveCompanyLoyaltyAmount($barang);

        return self::roundIdr(min($omset, $fixed));
    }

    public static function resolveCompanyLoyaltyAmount(Barang $barang): float
    {
        $stored = $barang->company_loyalty_amount;
        if ($stored !== null && (float) $stored > 0) {
            return (float) $stored;
        }

        return CompanyRevenueSettings::fixedAmount();
    }

    /**
     * @return array{
     *     sales_id: int,
     *     supervisor_id: ?int,
     *     total_cbm: float,
     *     omset: float,
     *     company_profit: float,
     *     sales_commission_base: float,
     *     commission_rate: float,
     *     sales_commission: float,
     *     cost_subtotal: float,
     *     markup: float,
     *     supervisor_amount: float,
     *     is_fixed: bool,
     * }|null
     */
    public static function earningsBreakdown(
        Barang $barang,
        ?float $ratePercent = null,
        ?int $salesId = null,
    ): ?array {
        $salesId ??= self::resolveSalesId($barang);
        if ($salesId === null) {
            return null;
        }

        $omset = self::billableBase($barang);
        $companyProfit = self::companyProfitForBarang($barang, $omset);
        $salesBase = self::roundIdr(max(0, $omset));
        $rate = $ratePercent ?? SalesCommissionSettings::rateForSalesId($salesId);
        $theoreticalSalesCommission = self::roundIdr($salesBase * $rate / 100);
        $supervisorMarketing = self::isSupervisorMarketingId($salesId);
        $salesCommission = $supervisorMarketing ? 0.0 : $theoreticalSalesCommission;

        $sellSubtotal = self::roundIdr(BarangShippingPricing::shippingSubtotalIdr($barang));
        $costSubtotal = self::roundIdr(BarangShippingPricing::costSubtotalIdr($barang));
        $markup = self::roundIdr(max(0, $sellSubtotal - $costSubtotal));
        $supervisorId = self::resolveSupervisorId($barang, $salesId);
        $supervisorAmount = $supervisorId !== null
            ? self::roundIdr(max(0, $markup - $theoreticalSalesCommission))
            : 0.0;

        return [
            'sales_id' => $salesId,
            'supervisor_id' => $supervisorId,
            'total_cbm' => (float) ($barang->total_cbm ?? 0),
            'omset' => $omset,
            'company_profit' => $companyProfit,
            'sales_commission_base' => $salesBase,
            'commission_rate' => $rate,
            'sales_commission' => $salesCommission,
            'cost_subtotal' => $costSubtotal,
            'markup' => $markup,
            'supervisor_amount' => $supervisorAmount,
            'is_fixed' => ShipmentEarningsSettlement::isBarangCommissionReleasable((int) $barang->id),
        ];
    }

    public static function resolveSalesId(Barang $barang): ?int
    {
        $markingValue = trim((string) ($barang->marking ?? ''));
        if ($markingValue === '') {
            return null;
        }

        $salesId = Marking::query()
            ->whereBarangMarkingValue($markingValue)
            ->whereNotNull('sales_id')
            ->value('sales_id');

        return $salesId ? (int) $salesId : null;
    }

    public static function resolveSupervisorId(Barang $barang, ?int $salesId = null): ?int
    {
        $salesId ??= self::resolveSalesId($barang);
        if ($salesId !== null && self::isSupervisorMarketingId($salesId)) {
            return $salesId;
        }

        $markingValue = trim((string) ($barang->marking ?? ''));
        if ($markingValue === '') {
            return null;
        }

        $marking = Marking::query()
            ->whereBarangMarkingValue($markingValue)
            ->first(['id', 'user_id']);

        if ($marking === null || $marking->user_id === null) {
            return null;
        }

        $owner = User::query()->find((int) $marking->user_id);
        if ($owner !== null && $owner->earnsCapitalGainOnly()) {
            return (int) $owner->id;
        }

        return null;
    }

    public static function isSupervisorMarketingId(?int $salesId): bool
    {
        if ($salesId === null || $salesId <= 0) {
            return false;
        }

        $salesUser = User::query()->find($salesId);

        return $salesUser !== null && $salesUser->earnsCapitalGainOnly();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snapshotForBarang(Barang $barang): ?array
    {
        $breakdown = self::earningsBreakdown($barang);
        if ($breakdown === null) {
            return null;
        }

        if ($breakdown['is_fixed']) {
            ShipmentEarningsSettlement::syncForBarang($barang);
        }

        $existing = SalesCommission::query()->where('barang_id', $barang->id)->first();
        $displayStatus = self::displayStatus($breakdown, $existing);

        if ($existing !== null && $existing->isSettled()) {
            return [
                'commission_id' => $existing->id,
                'sales_id' => (int) $existing->sales_id,
                'supervisor_id' => $existing->supervisor_id ? (int) $existing->supervisor_id : null,
                'total_cbm' => (float) $existing->total_cbm,
                'billable_base' => (float) $existing->billable_base,
                'company_profit' => (float) $existing->company_profit_amount,
                'sales_commission_base' => (float) $existing->sales_commission_base,
                'cost_subtotal' => (float) $existing->cost_subtotal,
                'markup' => (float) $existing->markup_amount,
                'supervisor_amount' => (float) $existing->supervisor_amount,
                'commission_rate' => (float) $existing->commission_rate,
                'estimated_amount' => (float) $existing->estimated_amount,
                'confirmed_amount' => $existing->confirmed_amount !== null
                    ? (float) $existing->confirmed_amount
                    : (float) $existing->estimated_amount,
                'status' => $displayStatus,
            ];
        }

        return [
            'commission_id' => $existing?->id,
            'sales_id' => $breakdown['sales_id'],
            'supervisor_id' => $breakdown['supervisor_id'],
            'total_cbm' => $breakdown['total_cbm'],
            'billable_base' => $breakdown['omset'],
            'company_profit' => $breakdown['company_profit'],
            'sales_commission_base' => $breakdown['sales_commission_base'],
            'cost_subtotal' => $breakdown['cost_subtotal'],
            'markup' => $breakdown['markup'],
            'supervisor_amount' => $breakdown['supervisor_amount'],
            'commission_rate' => $breakdown['commission_rate'],
            'estimated_amount' => $breakdown['sales_commission'],
            'confirmed_amount' => $breakdown['is_fixed'] ? $breakdown['sales_commission'] : null,
            'status' => $displayStatus,
        ];
    }

    private static function displayStatus(array $breakdown, ?SalesCommission $existing): string
    {
        if ($existing !== null && $existing->isSettled()) {
            return $existing->status === SalesCommission::STATUS_CAIR
                ? SalesCommission::STATUS_CAIR
                : SalesCommission::STATUS_FIXED;
        }

        return $breakdown['is_fixed']
            ? SalesCommission::STATUS_FIXED
            : SalesCommission::STATUS_WAITING;
    }

    /** @param  Builder<Barang>  $barangQuery */
    public static function applyTanggalFilter(Builder $barangQuery, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom !== null && $dateFrom !== '') {
            $barangQuery->whereDate('tanggal_masuk', '>=', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $barangQuery->whereDate('tanggal_masuk', '<=', $dateTo);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<string>  $excludedMarkings
     */
    public static function commissionRows(
        ?User $scopedUser = null,
        ?string $statusFilter = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        $query = Barang::query()
            ->select([
                'id', 'no_index', 'marking', 'status', 'total_cbm', 'total_gross_weight',
                'price_per_cbm', 'weight_price', 'weight_threshold_kg', 'tanggal_masuk', 'created_at',
            ]);
        self::applyCommissionBarangScope($query, $scopedUser);
        self::applyTanggalFilter($query, $dateFrom, $dateTo);
        $query->orderByDesc('id');

        $supervisorOnlyViewer = $scopedUser !== null && $scopedUser->isSupervisorOnly();
        $rows = [];
        foreach ($query->cursor() as $barang) {
            if (! self::passesCompanyRevenueFilters($barang, $marketingSalesId, $excludedMarkings)) {
                continue;
            }

            $snap = self::snapshotForBarang($barang);
            if ($snap === null) {
                continue;
            }

            if ($supervisorOnlyViewer && (int) ($snap['supervisor_id'] ?? 0) !== (int) $scopedUser->id) {
                continue;
            }

            $isFixedRow = in_array($snap['status'], [
                SalesCommission::STATUS_FIXED,
                SalesCommission::STATUS_CONFIRMED,
                SalesCommission::STATUS_CAIR,
            ], true);

            if ($statusFilter === 'waiting' && $isFixedRow) {
                continue;
            }
            if ($statusFilter === 'fixed' && ! $isFixedRow) {
                continue;
            }
            if ($statusFilter !== null && $statusFilter !== ''
                && ! in_array($statusFilter, ['waiting', 'fixed'], true)
                && $snap['status'] !== $statusFilter) {
                continue;
            }

            $salesName = User::query()->whereKey($snap['sales_id'])->value('name');
            $invoiceStatus = DB::table('invoice_items')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->where('invoice_items.barang_id', $barang->id)
                ->orderByDesc('invoice_items.id')
                ->value('invoices.status');

            $isFixed = in_array($snap['status'], [
                SalesCommission::STATUS_FIXED,
                SalesCommission::STATUS_CONFIRMED,
                SalesCommission::STATUS_CAIR,
            ], true);

            $partaiLabel = ShipmentEarningsSettlement::resolvePartaiLabel((int) $barang->id);
            $ownInvoicePaid = ShipmentEarningsSettlement::isBarangInvoicePaid((int) $barang->id);
            $partaiFullyPaid = $partaiLabel === null
                ? $ownInvoicePaid
                : ShipmentEarningsSettlement::isBarangCommissionReleasable((int) $barang->id);

            $rows[] = [
                'barang_id' => $barang->id,
                'commission_id' => $snap['commission_id'],
                'no_index' => $barang->no_index,
                'marking' => $barang->marking,
                'partai' => $partaiLabel,
                'partai_fully_paid' => $partaiFullyPaid,
                'own_invoice_paid' => $ownInvoicePaid,
                'barang_status' => $barang->status,
                'total_cbm' => (float) $barang->total_cbm,
                'billable_base' => $snap['billable_base'],
                'company_profit' => $snap['company_profit'] ?? 0,
                'sales_commission_base' => $snap['sales_commission_base'] ?? 0,
                'cost_subtotal' => $snap['cost_subtotal'] ?? 0,
                'markup' => $snap['markup'] ?? 0,
                'supervisor_amount' => $snap['supervisor_amount'] ?? 0,
                'commission_rate' => $snap['commission_rate'],
                'estimated_amount' => $snap['estimated_amount'],
                'confirmed_amount' => $snap['confirmed_amount'],
                'status' => $snap['status'],
                'is_fixed' => $isFixed,
                'sales_id' => $snap['sales_id'],
                'sales_name' => $salesName,
                'supervisor_id' => $snap['supervisor_id'] ?? null,
                'supervisor_name' => isset($snap['supervisor_id'])
                    ? User::query()->whereKey($snap['supervisor_id'])->value('name')
                    : null,
                'invoice_status' => $invoiceStatus,
                'tanggal_masuk' => $barang->tanggal_masuk?->format('d/m/Y'),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Barang>  $barangQuery
     */
    public static function applyCommissionBarangScope(Builder $barangQuery, ?User $user = null): void
    {
        if ($user !== null && Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictBarangsQuery($barangQuery, $user);

            return;
        }

        $whereSql = Marking::barangToMarkingWhereSql('markings', 'barangs');
        if ($whereSql === null) {
            $barangQuery->whereRaw('0 = 1');

            return;
        }

        $barangQuery->whereExists(function ($sub) use ($whereSql): void {
            $sub->selectRaw('1')
                ->from('markings')
                ->whereRaw($whereSql)
                ->whereNotNull('markings.sales_id');
        });
    }

    /**
     * @return array{
     *     estimasi: float,
     *     fix: float,
     *     estimasi_supervisor: float,
     *     fix_supervisor: float,
     *     estimasi_company: float,
     *     fix_company: float,
     *     waiting_count: int,
     * }
     */
    /**
     * @param  list<string>  $excludedMarkings
     */
    public static function totalsForUser(
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        $cacheKey = 'sales_revenue_totals:'.md5(json_encode([
            $scopedUser?->id,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, 120, fn (): array => self::computeTotalsForUser(
            $scopedUser,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
        ));
    }

    /**
     * @param  list<string>  $excludedMarkings
     * @return array{
     *     estimasi: float,
     *     fix: float,
     *     estimasi_supervisor: float,
     *     fix_supervisor: float,
     *     estimasi_company: float,
     *     fix_company: float,
     *     waiting_count: int,
     * }
     */
    private static function computeTotalsForUser(
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        $estimasi = 0.0;
        $fix = 0.0;
        $estimasiSupervisor = 0.0;
        $fixSupervisor = 0.0;
        $estimasiCompany = 0.0;
        $fixCompany = 0.0;
        $waitingCount = 0;
        $supervisorOnlyViewer = $scopedUser !== null && $scopedUser->isSupervisorOnly();

        $query = Barang::query();
        self::applyCommissionBarangScope($query, $scopedUser);
        self::applyTanggalFilter($query, $dateFrom, $dateTo);

        foreach ($query->orderByDesc('id')->cursor() as $barang) {
            if (! self::passesCompanyRevenueFilters($barang, $marketingSalesId, $excludedMarkings)) {
                continue;
            }

            $snap = self::snapshotForBarang($barang);
            if ($snap === null) {
                continue;
            }

            if ($supervisorOnlyViewer && (int) ($snap['supervisor_id'] ?? 0) !== (int) $scopedUser->id) {
                continue;
            }

            $isFixed = in_array($snap['status'], [
                SalesCommission::STATUS_FIXED,
                SalesCommission::STATUS_CONFIRMED,
                SalesCommission::STATUS_CAIR,
            ], true);

            $companyProfit = (float) ($snap['company_profit'] ?? 0);
            $supervisorAmount = (float) ($snap['supervisor_amount'] ?? 0);
            $salesAmount = (float) ($snap['confirmed_amount'] ?? $snap['estimated_amount']);
            $companyReleasable = ShipmentEarningsSettlement::isBarangCommissionReleasable((int) $barang->id);

            if ($supervisorOnlyViewer) {
                if ($isFixed) {
                    $fix += $supervisorAmount;
                } else {
                    $estimasi += $supervisorAmount;
                    $waitingCount++;
                }
            } else {
                if ($isFixed) {
                    $fix += $salesAmount;
                    $fixSupervisor += $supervisorAmount;
                } else {
                    $estimasi += (float) $snap['estimated_amount'];
                    $estimasiSupervisor += $supervisorAmount;
                    $waitingCount++;
                }

                if ($companyReleasable) {
                    $fixCompany += $companyProfit;
                } else {
                    $estimasiCompany += $companyProfit;
                }
            }
        }

        if ($supervisorOnlyViewer) {
            $estimasiSupervisor = $estimasi;
            $fixSupervisor = $fix;
        }

        return [
            'estimasi' => self::roundIdr($estimasi),
            'fix' => self::roundIdr($fix),
            'estimasi_supervisor' => self::roundIdr($estimasiSupervisor),
            'fix_supervisor' => self::roundIdr($fixSupervisor),
            'estimasi_company' => self::roundIdr($estimasiCompany),
            'fix_company' => self::roundIdr($fixCompany),
            'waiting_count' => $waitingCount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function stripCompanySecretsFromRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['billable_base'], $row['company_profit']);

            return $row;
        }, $rows);
    }

    /**
     * @param  list<string>  $excludedMarkings
     */
    public static function companyRevenueRows(
        ?User $scopedUser = null,
        ?string $statusFilter = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        $rows = self::commissionRows(
            $scopedUser,
            $statusFilter,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
        );

        return array_map(static function (array $row): array {
            $barangId = (int) $row['barang_id'];
            $releasable = ShipmentEarningsSettlement::isBarangCommissionReleasable($barangId);

            return [
                'barang_id' => $barangId,
                'no_index' => $row['no_index'],
                'marking' => $row['marking'],
                'partai' => $row['partai'],
                'tanggal_masuk' => $row['tanggal_masuk'],
                'billable_base' => $row['billable_base'],
                'company_profit' => $row['company_profit'],
                'status' => $releasable ? SalesCommission::STATUS_FIXED : SalesCommission::STATUS_WAITING,
                'is_fixed' => $releasable,
                'own_invoice_paid' => $row['own_invoice_paid'],
                'partai_fully_paid' => $row['partai_fully_paid'],
                'invoice_status' => $row['invoice_status'],
            ];
        }, $rows);
    }

    public static function adminWaitingConfirmCount(): int
    {
        return 0;
    }

    /**
     * @param  list<string>  $excludedMarkings
     */
    private static function passesCompanyRevenueFilters(
        Barang $barang,
        ?int $marketingSalesId,
        array $excludedMarkings,
    ): bool {
        $marking = trim((string) ($barang->marking ?? ''));
        if ($marking !== '' && $excludedMarkings !== []) {
            foreach ($excludedMarkings as $excluded) {
                if (strcasecmp(trim($excluded), $marking) === 0) {
                    return false;
                }
            }
        }

        if ($marketingSalesId === null) {
            return true;
        }

        return self::resolveSalesId($barang) === $marketingSalesId;
    }
}
