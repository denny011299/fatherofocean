<?php

declare(strict_types=1);

namespace App\Support;

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SalesRevenueCalculator
{
    /** @deprecated Use {@see SalesCommissionSettings::defaultRate()} */
    public const RATE_PERCENT = 2.5;

    /** @var array<string, ?int> */
    private static array $salesIdByMarking = [];

    /** @var array<int, ?string> */
    private static array $userNameById = [];

    /** @var array<int, bool> */
    private static array $supervisorMarketingById = [];

    /** @var array<int, ?int> */
    private static array $picIdBySalesId = [];

    /** @var array<int, float|null> */
    private static array $invoiceShippingByBarangId = [];

    private static function roundIdr(float $value): float
    {
        return round($value, 0);
    }

    public static function flushRequestCaches(): void
    {
        self::$salesIdByMarking = [];
        self::$userNameById = [];
        self::$supervisorMarketingById = [];
        self::$picIdBySalesId = [];
        self::$invoiceShippingByBarangId = [];
        ShipmentEarningsSettlement::flushCaches();
    }

    /**
     * @param  list<int>  $barangIds
     */
    public static function warmInvoiceShippingBases(array $barangIds): void
    {
        $barangIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $barangIds),
            static fn (int $id): bool => $id > 0 && ! array_key_exists($id, self::$invoiceShippingByBarangId),
        )));
        if ($barangIds === []) {
            return;
        }

        foreach ($barangIds as $id) {
            self::$invoiceShippingByBarangId[$id] = null;
        }

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_items.barang_id', $barangIds)
            ->whereNotIn('invoices.status', ['cancelled'])
            ->orderByDesc('invoice_items.id')
            ->get([
                'invoice_items.barang_id',
                'invoice_items.id as item_id',
                'invoice_items.line_total',
                'invoice_items.insurance',
                'invoice_items.extra',
                'invoices.status',
            ]);

        $latestByBarang = [];
        foreach ($rows as $row) {
            $barangId = (int) $row->barang_id;
            if (isset($latestByBarang[$barangId])) {
                continue;
            }
            $latestByBarang[$barangId] = $row;
            $shippingOnly = (float) $row->line_total
                - (float) $row->insurance
                - (float) $row->extra;
            self::$invoiceShippingByBarangId[$barangId] = $shippingOnly > 0
                ? self::roundIdr(max(0, $shippingOnly))
                : null;
        }
    }

    /**
     * Dasar komisi = subtotal pengiriman (CBM/berat), bukan line_total invoice penuh.
     * Biaya additional (barang_additional_prices) tidak masuk invoice line — otomatis terluar.
     */
    public static function billableBase(Barang $barang): float
    {
        $barangId = (int) ($barang->id ?? 0);
        if ($barangId > 0 && array_key_exists($barangId, self::$invoiceShippingByBarangId)) {
            $cached = self::$invoiceShippingByBarangId[$barangId];
            if ($cached !== null && $cached > 0) {
                return $cached;
            }

            return self::roundIdr(BarangShippingPricing::shippingSubtotalIdr($barang));
        }

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
                $amount = self::roundIdr(max(0, $shippingOnly));
                if ($barangId > 0) {
                    self::$invoiceShippingByBarangId[$barangId] = $amount;
                }

                return $amount;
            }
        }

        if ($barangId > 0) {
            self::$invoiceShippingByBarangId[$barangId] = null;
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

    /** Kas admin per pengiriman — pakai snapshot barang; sekali tercatat tidak ikut perubahan setting. */
    public static function resolveCompanyLoyaltyAmount(Barang $barang): float
    {
        $stored = $barang->company_loyalty_amount;
        if ($stored !== null && (float) $stored > 0) {
            return (float) $stored;
        }

        return self::ensureCompanyLoyaltySnapshot($barang);
    }

    /** Simpan tarif per kategori saat pertama kali dihitung agar tidak berubah saat setting diubah. */
    public static function ensureCompanyLoyaltySnapshot(Barang $barang): float
    {
        $stored = $barang->company_loyalty_amount;
        if ($stored !== null && (float) $stored > 0) {
            return (float) $stored;
        }

        $amount = CompanyRevenueSettings::profitPerShipmentForCbmType($barang->cbm_type);
        if (! $barang->exists || (int) $barang->id <= 0) {
            return $amount;
        }

        DB::table('barangs')
            ->where('id', $barang->id)
            ->where(function ($query): void {
                $query->whereNull('company_loyalty_amount')
                    ->orWhere('company_loyalty_amount', '<=', 0);
            })
            ->update([
                'company_loyalty_amount' => $amount,
                'updated_at' => now(),
            ]);

        $barang->company_loyalty_amount = $amount;

        return $amount;
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

        $cacheKey = mb_strtolower($markingValue);
        if (array_key_exists($cacheKey, self::$salesIdByMarking)) {
            return self::$salesIdByMarking[$cacheKey];
        }

        $salesId = Marking::query()
            ->whereBarangMarkingValue($markingValue)
            ->whereNotNull('sales_id')
            ->value('sales_id');

        return self::$salesIdByMarking[$cacheKey] = $salesId ? (int) $salesId : null;
    }

    public static function resolveSupervisorId(Barang $barang, ?int $salesId = null): ?int
    {
        $salesId ??= self::resolveSalesId($barang);
        if ($salesId === null || $salesId <= 0) {
            return null;
        }

        // 1) Wajib cek atasan dulu (master user → anak buah).
        // Contoh: Rodrico = sales → PIC = atasannya, bukan Rodrico sendiri.
        $fromHierarchy = self::resolvePicIdFromSalesHierarchy($salesId);
        if ($fromHierarchy !== null) {
            return $fromHierarchy;
        }

        // 2) User ini memang atasan (punya anak buah) — boleh jadi PIC untuk marking-nya.
        if (self::isPicParentUserId($salesId)) {
            return $salesId;
        }

        // 3) Supervisor murni (bukan sales) tanpa anak buah — PIC marking sendiri.
        if (self::isSupervisorOnlyUserId($salesId)) {
            return $salesId;
        }

        // 4) Fallback: owner marking hanya jika dia atasan / supervisor murni.
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

        $ownerId = (int) $marking->user_id;
        if ($ownerId <= 0 || $ownerId === $salesId) {
            return null;
        }

        if (self::isPicParentUserId($ownerId) || self::isSupervisorOnlyUserId($ownerId)) {
            return $ownerId;
        }

        // Sales biasa tanpa atasan → jangan masuk daftar KOMISI PIC.
        return null;
    }

    /** Parent (PIC / atasan) dari sales lewat tabel user_subordinates di master user. */
    public static function resolvePicIdFromSalesHierarchy(int $salesId): ?int
    {
        if ($salesId <= 0 || ! User::subordinatesTableReady()) {
            return null;
        }

        if (array_key_exists($salesId, self::$picIdBySalesId)) {
            return self::$picIdBySalesId[$salesId];
        }

        $parentIds = \Illuminate\Support\Facades\DB::table('user_subordinates')
            ->where('subordinate_user_id', $salesId)
            ->orderBy('id')
            ->pluck('parent_user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && $id !== $salesId)
            ->values()
            ->all();

        if ($parentIds === []) {
            return self::$picIdBySalesId[$salesId] = null;
        }

        $supervisorParent = User::query()
            ->whereIn('id', $parentIds)
            ->whereHas(
                'roles',
                static fn ($q) => $q->where('name', 'supervisor'),
            )
            ->orderBy('name')
            ->value('id');

        if ($supervisorParent !== null) {
            return self::$picIdBySalesId[$salesId] = (int) $supervisorParent;
        }

        return self::$picIdBySalesId[$salesId] = (int) $parentIds[0];
    }

    /** True jika user punya anak buah di master user (dia adalah atasan/PIC). */
    public static function isPicParentUserId(int $userId): bool
    {
        if ($userId <= 0 || ! User::subordinatesTableReady()) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('user_subordinates')
            ->where('parent_user_id', $userId)
            ->exists();
    }

    public static function isSupervisorOnlyUserId(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = User::query()->find($userId);

        return $user !== null && $user->isSupervisorOnly();
    }

    public static function isSupervisorMarketingId(?int $salesId): bool
    {
        if ($salesId === null || $salesId <= 0) {
            return false;
        }

        if (array_key_exists($salesId, self::$supervisorMarketingById)) {
            return self::$supervisorMarketingById[$salesId];
        }

        $salesUser = User::query()->find($salesId);

        return self::$supervisorMarketingById[$salesId] = $salesUser !== null
            && $salesUser->earnsCapitalGainOnly();
    }

    public static function userName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, self::$userNameById)) {
            return self::$userNameById[$userId];
        }

        return self::$userNameById[$userId] = User::query()->whereKey($userId)->value('name');
    }

    /**
     * @param  list<int>  $userIds
     */
    public static function warmUserNames(array $userIds): void
    {
        $missing = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0 && ! array_key_exists($id, self::$userNameById),
        )));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            self::$userNameById[$id] = null;
        }

        foreach (User::query()->whereIn('id', $missing)->get(['id', 'name']) as $user) {
            self::$userNameById[(int) $user->id] = (string) $user->name;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snapshotForBarang(Barang $barang, bool $persistSideEffects = true): ?array
    {
        return self::snapshotForBarangWithCommission($barang, null, $persistSideEffects);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snapshotForBarangWithCommission(
        Barang $barang,
        ?SalesCommission $existing = null,
        bool $persistSideEffects = true,
    ): ?array {
        $breakdown = self::earningsBreakdown($barang);
        if ($breakdown === null) {
            return null;
        }

        if ($breakdown['is_fixed'] && $persistSideEffects) {
            // List loads skip incentive recalc; payment webhooks still sync incentives.
            $synced = ShipmentEarningsSettlement::syncForBarang(
                $barang,
                syncIncentive: false,
                breakdown: $breakdown,
            );
            if ($synced !== null) {
                $existing = $synced;
            }
        }

        $existing ??= SalesCommission::query()->where('barang_id', $barang->id)->first();
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

    /** @param  Builder<Barang>  $barangQuery */
    public static function applyPartaiFilter(Builder $barangQuery, ?string $partai): void
    {
        $partai = trim((string) $partai);
        if ($partai === '') {
            return;
        }

        if ($partai === 'belum_di_muat') {
            $barangQuery->whereDoesntHave('containers');

            return;
        }

        // Match containers.partai, or container_no when partai is empty (same fallback as resolvePartaiLabel).
        $barangQuery->whereHas('containers', static function (Builder $containerQuery) use ($partai): void {
            $containerQuery->where(static function (Builder $inner) use ($partai): void {
                $inner->where('partai', $partai)
                    ->orWhere(static function (Builder $fallback) use ($partai): void {
                        $fallback
                            ->where(static function (Builder $emptyPartai): void {
                                $emptyPartai->whereNull('partai')->orWhere('partai', '');
                            })
                            ->where(static function (Builder $label) use ($partai): void {
                                $label->where('container_no', $partai)
                                    ->orWhereRaw("CONCAT('#', containers.id) = ?", [$partai]);
                            });
                    });
            });
        });
    }

    /**
     * Keep only rows whose displayed partai label matches the filter (AND with SQL whereHas).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterRowsByPartaiLabel(array $rows, ?string $partai): array
    {
        $partai = trim((string) $partai);
        if ($partai === '') {
            return $rows;
        }

        if ($partai === 'belum_di_muat') {
            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => trim((string) ($row['partai'] ?? '')) === '',
            ));
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['partai'] ?? '') === $partai,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function dedupeRowsByBarangId(array $rows): array
    {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $barangId = (int) ($row['barang_id'] ?? 0);
            if ($barangId > 0) {
                if (isset($seen[$barangId])) {
                    continue;
                }
                $seen[$barangId] = true;
            }
            $unique[] = $row;
        }

        return $unique;
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
        ?string $partai = null,
    ): array {
        $query = Barang::query()
            ->select([
                'id', 'no_index', 'marking', 'status', 'total_cbm', 'total_gross_weight',
                'price_per_cbm', 'weight_price', 'weight_threshold_kg', 'tanggal_masuk', 'created_at',
                'cbm_type', 'company_loyalty_amount',
            ]);
        self::applyCommissionBarangScope($query, $scopedUser);
        self::applyTanggalFilter($query, $dateFrom, $dateTo);
        self::applyPartaiFilter($query, $partai);
        $query->orderByDesc('id');

        $barangs = $query->get();
        $barangIds = $barangs->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        ShipmentEarningsSettlement::warmCachesForBarangIds($barangIds);
        self::warmInvoiceShippingBases($barangIds);

        $commissionsByBarangId = $barangIds === []
            ? collect()
            : SalesCommission::query()
                ->whereIn('barang_id', $barangIds)
                ->get()
                ->keyBy(static fn (SalesCommission $row): int => (int) $row->barang_id);

        $markingValues = $barangs
            ->pluck('marking')
            ->map(static fn ($m): string => trim((string) $m))
            ->filter(static fn (string $m): bool => $m !== '')
            ->unique()
            ->values()
            ->all();

        if ($markingValues !== []) {
            foreach ($markingValues as $value) {
                $salesId = Marking::query()
                    ->whereBarangMarkingValue($value)
                    ->whereNotNull('sales_id')
                    ->value('sales_id');
                self::$salesIdByMarking[mb_strtolower($value)] = $salesId ? (int) $salesId : null;
            }
        }

        $invoiceStatusByBarang = [];
        if ($barangIds !== []) {
            $statusRows = DB::table('invoice_items')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->whereIn('invoice_items.barang_id', $barangIds)
                ->orderByDesc('invoice_items.id')
                ->get(['invoice_items.barang_id', 'invoice_items.id as item_id', 'invoices.status']);

            foreach ($statusRows as $row) {
                $barangId = (int) $row->barang_id;
                if (array_key_exists($barangId, $invoiceStatusByBarang)) {
                    continue;
                }
                $invoiceStatusByBarang[$barangId] = $row->status;
            }
        }

        $supervisorOnlyViewer = $scopedUser !== null && $scopedUser->isSupervisorOnly();
        $rows = [];
        $pendingUserIds = [];

        foreach ($barangs as $barang) {
            if (! self::passesCompanyRevenueFilters($barang, $marketingSalesId, $excludedMarkings)) {
                continue;
            }

            $snap = self::snapshotForBarangWithCommission(
                $barang,
                $commissionsByBarangId->get((int) $barang->id),
            );
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

            $salesId = (int) $snap['sales_id'];
            $supervisorId = isset($snap['supervisor_id']) ? (int) $snap['supervisor_id'] : null;
            if ($salesId > 0) {
                $pendingUserIds[$salesId] = true;
            }
            if ($supervisorId !== null && $supervisorId > 0) {
                $pendingUserIds[$supervisorId] = true;
            }

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
                'kategori' => BarangListProductsExport::resolveKategoriLabel($barang->cbm_type),
                'loyalty_snapshot' => self::resolveCompanyLoyaltyAmount($barang),
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
                'is_fixed' => $isFixedRow,
                'sales_id' => $salesId,
                'sales_name' => null,
                'supervisor_id' => $supervisorId,
                'supervisor_name' => null,
                'invoice_status' => $invoiceStatusByBarang[(int) $barang->id] ?? null,
                'tanggal_masuk' => $barang->tanggal_masuk?->format('d/m/Y'),
                '_pending_sales_id' => $salesId,
                '_pending_supervisor_id' => $supervisorId,
            ];
        }

        self::warmUserNames(array_keys($pendingUserIds));

        foreach ($rows as &$row) {
            $salesId = (int) ($row['_pending_sales_id'] ?? 0);
            $supervisorId = isset($row['_pending_supervisor_id']) ? (int) $row['_pending_supervisor_id'] : null;
            $row['sales_name'] = self::userName($salesId);
            $row['supervisor_name'] = $supervisorId !== null ? self::userName($supervisorId) : null;
            unset($row['_pending_sales_id'], $row['_pending_supervisor_id']);
        }
        unset($row);

        return self::filterRowsByPartaiLabel(
            self::dedupeRowsByBarangId($rows),
            $partai,
        );
    }

    /**
     * @param  Builder<Barang>  $barangQuery
     */
    public static function applyCommissionBarangScope(Builder $barangQuery, ?User $user = null): void
    {
        if ($user?->hasRole('sales')) {
            Marking::restrictBarangsQuery($barangQuery, $user);
        }

        $whereSql = Marking::barangToMarkingWhereSql('markings', 'barangs');
        if ($whereSql === null) {
            $barangQuery->whereRaw('0 = 1');

            return;
        }

        $barangQuery->where(function (Builder $outer) use ($whereSql, $user): void {
            $outer->whereExists(function ($sub) use ($whereSql, $user): void {
                $sub->selectRaw('1')
                    ->from('markings')
                    ->whereRaw($whereSql)
                    ->whereNotNull('markings.sales_id');
                if ($user?->hasRole('sales')) {
                    $sub->where('markings.sales_id', $user->id);
                }
            });

            if ($user?->hasRole('supervisor')) {
                $salesIds = $user->portalScopeUserIds();
                $outer->orWhereExists(function ($sub) use ($whereSql, $user, $salesIds): void {
                    $sub->selectRaw('1')
                        ->from('markings')
                        ->whereRaw($whereSql)
                        ->where(function (QueryBuilder $markingScope) use ($user, $salesIds): void {
                            $markingScope
                                ->whereIn('markings.sales_id', $salesIds)
                                ->orWhere('markings.user_id', $user->id);
                        });
                });
            }
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
        ?string $partai = null,
    ): array {
        $cacheKey = 'sales_revenue_totals:'.md5(json_encode([
            $scopedUser?->id,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
            $partai,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, 120, fn (): array => self::computeTotalsForUser(
            $scopedUser,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
            $partai,
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
        ?string $partai = null,
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
        self::applyPartaiFilter($query, $partai);

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
            'category_totals' => self::buildCompanyCategoryTotals(
                $scopedUser,
                $dateFrom,
                $dateTo,
                $marketingSalesId,
                $excludedMarkings,
            ),
        ];
    }

    /**
     * @param  list<string>  $excludedMarkings
     * @return list<array{
     *     kategori: string,
     *     shipment_count: int,
     *     estimasi: float,
     *     fix: float,
     *     total: float,
     * }>
     */
    public static function companyCategoryTotals(
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        return self::buildCompanyCategoryTotals(
            $scopedUser,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
        );
    }

    /**
     * @param  list<string>  $excludedMarkings
     * @return list<array{
     *     kategori: string,
     *     shipment_count: int,
     *     estimasi: float,
     *     fix: float,
     *     total: float,
     * }>
     */
    private static function buildCompanyCategoryTotals(
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $marketingSalesId = null,
        array $excludedMarkings = [],
    ): array {
        /** @var array<string, array{shipment_count: int, estimasi: float, fix: float}> $buckets */
        $buckets = [];
        $supervisorOnlyViewer = $scopedUser !== null && $scopedUser->isSupervisorOnly();

        if ($supervisorOnlyViewer) {
            return [];
        }

        $query = Barang::query()->select(['id', 'marking', 'cbm_type', 'company_loyalty_amount']);
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

            $kategori = BarangListProductsExport::resolveKategoriLabel($barang->cbm_type);
            if ($kategori === '' || $kategori === '-') {
                continue;
            }

            $companyProfit = (float) ($snap['company_profit'] ?? 0);
            if ($companyProfit <= 0) {
                continue;
            }

            $buckets[$kategori] ??= [
                'shipment_count' => 0,
                'estimasi' => 0.0,
                'fix' => 0.0,
            ];
            $buckets[$kategori]['shipment_count']++;

            if (ShipmentEarningsSettlement::isBarangCommissionReleasable((int) $barang->id)) {
                $buckets[$kategori]['fix'] += $companyProfit;
            } else {
                $buckets[$kategori]['estimasi'] += $companyProfit;
            }
        }

        $totals = [];
        foreach ($buckets as $kategori => $bucket) {
            $estimasi = self::roundIdr($bucket['estimasi']);
            $fix = self::roundIdr($bucket['fix']);
            $total = self::roundIdr($estimasi + $fix);
            if ($total <= 0) {
                continue;
            }

            $totals[] = [
                'kategori' => $kategori,
                'shipment_count' => $bucket['shipment_count'],
                'estimasi' => $estimasi,
                'fix' => $fix,
                'total' => $total,
            ];
        }

        usort(
            $totals,
            static fn (array $left, array $right): int => strcmp($left['kategori'], $right['kategori']),
        );

        return $totals;
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
        ?string $partai = null,
    ): array {
        $rows = self::commissionRows(
            $scopedUser,
            $statusFilter,
            $dateFrom,
            $dateTo,
            $marketingSalesId,
            $excludedMarkings,
            $partai,
        );

        return array_map(static function (array $row): array {
            $barangId = (int) $row['barang_id'];
            $releasable = ShipmentEarningsSettlement::isBarangCommissionReleasable($barangId);

            return [
                'barang_id' => $barangId,
                'no_index' => $row['no_index'],
                'marking' => $row['marking'],
                'kategori' => $row['kategori'] ?? '',
                'loyalty_snapshot' => (float) ($row['loyalty_snapshot'] ?? 0),
                'partai' => $row['partai'],
                'tanggal_masuk' => $row['tanggal_masuk'],
                'billable_base' => $row['billable_base'],
                'company_profit' => $row['company_profit'],
                'status' => $releasable ? SalesCommission::STATUS_FIXED : SalesCommission::STATUS_WAITING,
                'is_fixed' => $releasable,
            ];
        }, $rows);
    }

    /**
     * Status Invoice badge: sync with finance invoice payment, not sales payout proofs.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function enrichRowPaymentStatus(array $row): array
    {
        $invoicePaid = (bool) ($row['own_invoice_paid'] ?? false)
            || (($row['invoice_status'] ?? null) === 'paid');

        $row['is_paid'] = $invoicePaid;
        $row['payment_status'] = $invoicePaid ? 'paid' : 'unpaid';

        return $row;
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
