<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\SalesMarkingCommissionOverride;
use App\Models\SalesMarkingPayout;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class SalesMarkingCommissionDetail
{
    /**
     * @return array<string, float>
     */
    public static function paidTotalsBySalesMarking(): array
    {
        if (! Schema::hasTable('sales_marking_payouts')) {
            return [];
        }

        $totals = [];
        foreach (
            SalesMarkingPayout::query()
                ->selectRaw('sales_id, marking, SUM(amount) as total_amount')
                ->groupBy('sales_id', 'marking')
                ->get() as $payout
        ) {
            $key = self::pairKey((int) $payout->sales_id, (string) $payout->marking);
            $totals[$key] = round(($totals[$key] ?? 0.0) + (float) $payout->total_amount, 2);
        }

        return $totals;
    }

    public static function paidTotalForPair(int $salesId, string $marking): float
    {
        if (! Schema::hasTable('sales_marking_payouts') || $salesId <= 0) {
            return 0.0;
        }

        $marking = trim($marking);
        if ($marking === '') {
            return 0.0;
        }

        $total = SalesMarkingPayout::query()
            ->where('sales_id', $salesId)
            ->whereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($marking)])
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public static function commissionTotalsBySalesMarking(array $rows): array
    {
        // Kept for callers that need sum-of-line commissions; list "owed" uses owedTotalsBySalesMarking().
        $totals = [];

        foreach ($rows as $row) {
            if (! ($row['is_fixed'] ?? false)) {
                continue;
            }

            $salesId = (int) ($row['sales_id'] ?? 0);
            $marking = trim((string) ($row['marking'] ?? ''));
            if ($salesId <= 0 || $marking === '') {
                continue;
            }

            $amount = (float) ($row['confirmed_amount'] ?? $row['estimated_amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $key = self::pairKey($salesId, $marking);
            $totals[$key] = round(($totals[$key] ?? 0.0) + $amount, 2);
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function isMarkingPaid(int $salesId, string $marking, array $rows, array $paidTotals): bool
    {
        $key = self::pairKey($salesId, $marking);
        $owed = (float) (self::owedTotalsBySalesMarking($rows)[$key] ?? 0.0);
        if ($owed <= 0) {
            return false;
        }

        return self::isCommissionFullyPaid((float) ($paidTotals[$key] ?? 0.0), $owed);
    }

    /**
     * Owed komisi per sales+marking — same formula as detail modal:
     * override total, else round(sum(omset) × rate / 100).
     * (Sum of per-item komisi can differ by Rp1 due to rounding.)
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public static function owedTotalsBySalesMarking(array $rows): array
    {
        $omsetByKey = [];
        $rateByKey = [];
        $salesIdByKey = [];

        foreach ($rows as $row) {
            $salesId = (int) ($row['sales_id'] ?? 0);
            $marking = trim((string) ($row['marking'] ?? ''));
            if ($salesId <= 0 || $marking === '') {
                continue;
            }

            $key = self::pairKey($salesId, $marking);
            $salesIdByKey[$key] = $salesId;

            // Match detail(): sum EVERY kiriman omset shown for the marking.
            $omsetByKey[$key] = round(
                ($omsetByKey[$key] ?? 0.0) + (float) ($row['sales_commission_base'] ?? 0),
                2,
            );

            $rate = (float) ($row['commission_rate'] ?? 0);
            if ($rate > 0 && ! isset($rateByKey[$key])) {
                $rateByKey[$key] = $rate;
            }
        }

        $overrides = self::overrideTotalsForRows($rows);
        $totals = [];

        foreach ($omsetByKey as $key => $omset) {
            if (isset($overrides[$key])) {
                $totals[$key] = $overrides[$key];

                continue;
            }

            $rate = $rateByKey[$key] ?? 0.0;
            if ($rate <= 0) {
                $rate = SalesCommissionSettings::rateForSalesId($salesIdByKey[$key] ?? null);
            }

            $totals[$key] = round($omset * $rate / 100, 0);
        }

        // Override-only pairs (no omset rows in current list filter) still need owed.
        foreach ($overrides as $key => $overrideTotal) {
            if (! isset($totals[$key])) {
                $totals[$key] = $overrideTotal;
            }
        }

        return $totals;
    }

    public static function isCommissionFullyPaid(float $paid, float $owed): bool
    {
        if ($owed <= 0) {
            return false;
        }

        return round($paid, 2) >= round($owed, 2);
    }

    /**
     * Enrich list rows with invoice payment_status and commission payout status (batched).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function enrichListPaymentStatuses(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $paidTotals = self::paidTotalsBySalesMarking();
        $owedTotals = self::owedTotalsBySalesMarking($rows);

        return array_map(static function (array $row) use ($paidTotals, $owedTotals): array {
            $row = SalesRevenueCalculator::enrichRowPaymentStatus($row);

            $salesId = (int) ($row['sales_id'] ?? 0);
            $marking = trim((string) ($row['marking'] ?? ''));
            $key = self::pairKey($salesId, $marking);
            $owed = (float) ($owedTotals[$key] ?? 0.0);
            $paid = (float) ($paidTotals[$key] ?? 0.0);
            $commissionPaid = self::isCommissionFullyPaid($paid, $owed);

            $row['commission_paid'] = $commissionPaid;
            $row['commission_status'] = $commissionPaid ? 'paid' : 'unpaid';
            $row['commission_payment_status'] = $row['commission_status'];

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private static function overrideTotalsForRows(array $rows): array
    {
        if (! Schema::hasTable('sales_marking_commission_overrides') || $rows === []) {
            return [];
        }

        $salesIds = [];
        $pairKeys = [];
        foreach ($rows as $row) {
            $salesId = (int) ($row['sales_id'] ?? 0);
            $marking = trim((string) ($row['marking'] ?? ''));
            if ($salesId <= 0 || $marking === '') {
                continue;
            }
            $salesIds[$salesId] = true;
            $pairKeys[self::pairKey($salesId, $marking)] = true;
        }

        if ($salesIds === []) {
            return [];
        }

        $totals = [];
        foreach (
            SalesMarkingCommissionOverride::query()
                ->whereIn('sales_id', array_keys($salesIds))
                ->get(['sales_id', 'marking', 'total_komisi']) as $override
        ) {
            $key = self::pairKey((int) $override->sales_id, (string) $override->marking);
            if (! isset($pairKeys[$key])) {
                continue;
            }
            $totals[$key] = round((float) $override->total_komisi, 2);
        }

        return $totals;
    }

    public static function findOverride(int $salesId, string $marking): ?SalesMarkingCommissionOverride
    {
        if (! Schema::hasTable('sales_marking_commission_overrides') || $salesId <= 0) {
            return null;
        }

        $marking = trim($marking);
        if ($marking === '') {
            return null;
        }

        return SalesMarkingCommissionOverride::query()
            ->where('sales_id', $salesId)
            ->whereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($marking)])
            ->first();
    }

    /**
     * @return array{
     *     sales_id: int,
     *     sales_name: string,
     *     marking: string,
     *     pic: ?string,
     *     customer_name: ?string,
     *     status: string,
     *     is_paid: bool,
     *     commission_status: string,
     *     commission_paid: bool,
     *     invoice_status: 'paid'|'partial'|'unpaid',
     *     invoice_paid: bool,
     *     total_omset: float,
     *     commission_rate: float,
     *     total_komisi: float,
     *     total_paid: float,
     *     remaining: float,
     *     can_edit_komisi: bool,
     *     items: list<array<string, mixed>>,
     *     payouts: list<array<string, mixed>>,
     * }
     */
    public static function detail(
        int $salesId,
        string $marking,
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $partai = null,
    ): array {
        $marking = trim($marking);
        $items = [];
        $totalOmset = 0.0;
        $rate = 0.0;
        $seenBarangIds = [];
        $invoicePaidFlags = [];

        $supervisorOnlyViewer = $scopedUser !== null && $scopedUser->isSupervisorOnly();

        $query = Barang::query()
            ->select([
                'id', 'no_index', 'marking', 'status', 'total_cbm', 'total_gross_weight',
                'price_per_cbm', 'weight_price', 'weight_threshold_kg', 'tanggal_masuk', 'created_at',
                'cbm_type', 'company_loyalty_amount', 'material', 'notes',
            ]);
        SalesRevenueCalculator::applyCommissionBarangScope($query, $scopedUser);
        SalesRevenueCalculator::applyTanggalFilter($query, $dateFrom, $dateTo);
        SalesRevenueCalculator::applyPartaiFilter($query, $partai);

        if ($marking !== '') {
            // Exact match uses barangs.marking index; value comes from the list row.
            $query->where('marking', $marking);
        } else {
            $query->whereRaw('0 = 1');
        }

        $query->orderByDesc('id');

        $barangModels = $query->get();
        $barangIds = $barangModels->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        ShipmentEarningsSettlement::warmCachesForBarangIds($barangIds);
        SalesRevenueCalculator::warmInvoiceShippingBases($barangIds);

        foreach ($barangModels as $barang) {
            $barangId = (int) $barang->id;
            if ($barangId > 0 && isset($seenBarangIds[$barangId])) {
                continue;
            }

            $snap = SalesRevenueCalculator::snapshotForBarang($barang, persistSideEffects: false);
            if ($snap === null) {
                continue;
            }

            if ((int) ($snap['sales_id'] ?? 0) !== $salesId) {
                continue;
            }

            if ($supervisorOnlyViewer && (int) ($snap['supervisor_id'] ?? 0) !== (int) $scopedUser->id) {
                continue;
            }

            $partaiLabel = ShipmentEarningsSettlement::resolvePartaiLabel($barangId);
            if ($partai !== null && $partai !== '') {
                $label = trim((string) ($partaiLabel ?? ''));
                if ($partai === 'belum_di_muat') {
                    if ($label !== '') {
                        continue;
                    }
                } elseif ($label !== $partai) {
                    continue;
                }
            }

            if ($barangId > 0) {
                $seenBarangIds[$barangId] = true;
            }

            $isFixed = in_array($snap['status'], [
                SalesCommission::STATUS_FIXED,
                SalesCommission::STATUS_CONFIRMED,
                SalesCommission::STATUS_CAIR,
            ], true);

            $omset = (float) ($snap['sales_commission_base'] ?? 0);
            $komisi = $isFixed
                ? (float) ($snap['confirmed_amount'] ?? $snap['estimated_amount'] ?? 0)
                : (float) ($snap['estimated_amount'] ?? 0);

            if ($rate <= 0 && isset($snap['commission_rate'])) {
                $rate = (float) $snap['commission_rate'];
            }

            // Sum EVERY kiriman omset shown in the detail table (not only net-fix rows).
            $totalOmset += $omset;

            $ownInvoicePaid = $barangId > 0
                ? ShipmentEarningsSettlement::isBarangInvoicePaid($barangId)
                : false;
            $invoicePaidFlags[] = $ownInvoicePaid;

            $itemLabel = trim((string) ($barang->material ?? $barang->notes ?? $marking));

            $items[] = [
                'barang_id' => $barangId,
                'no_index' => $barang->no_index,
                'item' => $itemLabel !== '' ? $itemLabel : '-',
                'cbm' => (float) ($barang->total_cbm ?? 0),
                'berat' => (float) ($barang->total_gross_weight ?? 0),
                'partai' => $partaiLabel,
                'omset' => $omset,
                'komisi' => $komisi,
                'is_fixed' => $isFixed,
                'own_invoice_paid' => $ownInvoicePaid,
            ];
        }

        $totalOmset = round($totalOmset, 2);
        if ($rate <= 0) {
            $rate = SalesCommissionSettings::rateForSalesId($salesId);
        }

        $override = self::findOverride($salesId, $marking);
        if ($override !== null) {
            $rate = (float) $override->commission_rate;
            $totalKomisi = round((float) $override->total_komisi, 2);
        } else {
            $totalKomisi = round($totalOmset * $rate / 100, 0);
        }

        $totalPaid = self::paidTotalForPair($salesId, $marking);
        $remaining = max(0.0, round($totalKomisi - $totalPaid, 2));
        $commissionPaid = self::isCommissionFullyPaid($totalPaid, $totalKomisi);

        // Group invoice status across items shown in this detail (not per clicked row).
        // paid = all invoice-paid; partial = mixed; unpaid = none / empty.
        $invoiceStatus = self::invoiceStatusFromFlags($invoicePaidFlags);
        $invoicePaid = $invoiceStatus === 'paid';

        $markingRow = Marking::query()
            ->where('sales_id', $salesId)
            ->where(static function ($query) use ($marking): void {
                $query->where('code', $marking)
                    ->orWhere('customer_name', $marking);
            })
            ->first(['pic', 'customer_name']);

        $salesName = User::query()->whereKey($salesId)->value('name');

        $payouts = [];
        if (Schema::hasTable('sales_marking_payouts')) {
            $payouts = SalesMarkingPayout::query()
                ->with('creator:id,name')
                ->where('sales_id', $salesId)
                ->whereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($marking)])
                ->orderByDesc('payout_date')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (SalesMarkingPayout $p): array => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'payout_date' => $p->payout_date?->format('d/m/Y'),
                    'proof_url' => $p->proofUrl(),
                    'note' => $p->note,
                    'created_by_name' => $p->creator?->name,
                    'created_at' => $p->created_at?->format('d/m/Y H:i'),
                ])
                ->values()
                ->all();
        }

        return [
            'sales_id' => $salesId,
            'sales_name' => (string) ($salesName ?? ''),
            'marking' => $marking,
            'pic' => $markingRow?->pic,
            'customer_name' => $markingRow?->customer_name,
            // Legacy aliases: commission payout status (kept for existing JS callers).
            'status' => $commissionPaid ? 'paid' : 'unpaid',
            'is_paid' => $commissionPaid,
            'commission_status' => $commissionPaid ? 'paid' : 'unpaid',
            'commission_paid' => $commissionPaid,
            'invoice_status' => $invoiceStatus,
            'invoice_paid' => $invoicePaid,
            'total_omset' => $totalOmset,
            'commission_rate' => $rate,
            'total_komisi' => $totalKomisi,
            'total_paid' => $totalPaid,
            'remaining' => $remaining,
            'can_edit_komisi' => auth()->user()?->can('upload sales salary proof') ?? false,
            'items' => $items,
            'payouts' => $payouts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function saveOverride(
        int $salesId,
        string $marking,
        float $commissionRate,
        float $totalKomisi,
        ?int $updatedBy = null,
        ?User $scopedUser = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $partai = null,
    ): array {
        if (! Schema::hasTable('sales_marking_commission_overrides')) {
            throw new \RuntimeException('Override table missing.');
        }

        $marking = trim($marking);
        $commissionRate = max(0.0, min(100.0, round($commissionRate, 2)));
        $totalKomisi = max(0.0, round($totalKomisi, 0));

        $existing = self::findOverride($salesId, $marking);
        if ($existing !== null) {
            $existing->update([
                'commission_rate' => $commissionRate,
                'total_komisi' => $totalKomisi,
                'updated_by' => $updatedBy,
                'marking' => $marking,
            ]);
        } else {
            SalesMarkingCommissionOverride::query()->create([
                'sales_id' => $salesId,
                'marking' => $marking,
                'commission_rate' => $commissionRate,
                'total_komisi' => $totalKomisi,
                'updated_by' => $updatedBy,
            ]);
        }

        return self::detail($salesId, $marking, $scopedUser, $dateFrom, $dateTo, $partai);
    }

    /**
     * Aggregate per-item invoice paid flags for Status Invoice in detail.
     *
     * @param  list<bool>  $flags
     * @return 'paid'|'partial'|'unpaid'
     */
    public static function invoiceStatusFromFlags(array $flags): string
    {
        if ($flags === []) {
            return 'unpaid';
        }

        $paidCount = 0;
        foreach ($flags as $flag) {
            if ($flag) {
                $paidCount++;
            }
        }

        if ($paidCount === count($flags)) {
            return 'paid';
        }

        if ($paidCount === 0) {
            return 'unpaid';
        }

        return 'partial';
    }

    private static function pairKey(int $salesId, string $marking): string
    {
        return $salesId.'|'.mb_strtolower(trim($marking));
    }
}
