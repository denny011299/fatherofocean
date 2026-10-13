<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\SalesCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ShipmentEarningsSettlement
{
    /** @var array<int, list<int>> */
    private static array $invoiceIdsByBarang = [];

    /** @var array<int, list<int>> */
    private static array $containerIdsByBarang = [];

    /** @var array<int, bool> */
    private static array $invoicePaidCache = [];

    /** @var array<int, bool> */
    private static array $barangInvoicePaidCache = [];

    /** @var array<int, bool> */
    private static array $partaiAllPaidCache = [];

    /** @var array<int, bool> */
    private static array $releasableCache = [];

    /** @var array<int, ?string> */
    private static array $partaiLabelCache = [];

    /** @var array<int, list<int>> */
    private static array $barangIdsByContainer = [];

    public static function schemaReady(): bool
    {
        return Schema::hasTable('sales_commissions')
            && Schema::hasColumn('sales_commissions', 'company_profit_amount');
    }

    /** Clear request-scoped memoization (tests / nested batch jobs). */
    public static function flushCaches(): void
    {
        self::$invoiceIdsByBarang = [];
        self::$containerIdsByBarang = [];
        self::$invoicePaidCache = [];
        self::$barangInvoicePaidCache = [];
        self::$partaiAllPaidCache = [];
        self::$releasableCache = [];
        self::$partaiLabelCache = [];
        self::$barangIdsByContainer = [];
    }

    /**
     * Prefetch invoice/container/payment graphs for a barang set to kill N+1 in list builds.
     *
     * @param  list<int>  $barangIds
     */
    public static function warmCachesForBarangIds(array $barangIds): void
    {
        $barangIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $barangIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($barangIds === []) {
            return;
        }

        $missingInvoice = array_values(array_filter(
            $barangIds,
            static fn (int $id): bool => ! array_key_exists($id, self::$invoiceIdsByBarang),
        ));
        if ($missingInvoice !== []) {
            foreach ($missingInvoice as $id) {
                self::$invoiceIdsByBarang[$id] = [];
            }

            $rows = DB::table('invoice_items')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->whereIn('invoice_items.barang_id', $missingInvoice)
                ->whereNotIn('invoices.status', ['cancelled'])
                ->select(['invoice_items.barang_id', 'invoices.id as invoice_id'])
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $barangId = (int) $row->barang_id;
                $invoiceId = (int) $row->invoice_id;
                if (! in_array($invoiceId, self::$invoiceIdsByBarang[$barangId], true)) {
                    self::$invoiceIdsByBarang[$barangId][] = $invoiceId;
                }
            }
        }

        $missingContainers = array_values(array_filter(
            $barangIds,
            static fn (int $id): bool => ! array_key_exists($id, self::$containerIdsByBarang),
        ));
        if ($missingContainers !== []) {
            foreach ($missingContainers as $id) {
                self::$containerIdsByBarang[$id] = [];
            }

            $containerRows = DB::table('container_barangs')
                ->whereIn('barang_id', $missingContainers)
                ->select(['barang_id', 'container_id'])
                ->get();

            $containerIds = [];
            foreach ($containerRows as $row) {
                $barangId = (int) $row->barang_id;
                $containerId = (int) $row->container_id;
                if ($containerId <= 0) {
                    continue;
                }
                if (! in_array($containerId, self::$containerIdsByBarang[$barangId], true)) {
                    self::$containerIdsByBarang[$barangId][] = $containerId;
                }
                $containerIds[$containerId] = true;
            }

            $containerIdList = array_keys($containerIds);
            if ($containerIdList !== []) {
                $peerRows = DB::table('container_barangs')
                    ->whereIn('container_id', $containerIdList)
                    ->select(['container_id', 'barang_id'])
                    ->get();

                foreach ($containerIdList as $containerId) {
                    if (! array_key_exists($containerId, self::$barangIdsByContainer)) {
                        self::$barangIdsByContainer[$containerId] = [];
                    }
                }

                foreach ($peerRows as $row) {
                    $containerId = (int) $row->container_id;
                    $peerBarangId = (int) $row->barang_id;
                    if (! in_array($peerBarangId, self::$barangIdsByContainer[$containerId], true)) {
                        self::$barangIdsByContainer[$containerId][] = $peerBarangId;
                    }
                }

                $labelRows = DB::table('containers')
                    ->whereIn('id', $containerIdList)
                    ->get(['id', 'partai', 'container_no']);

                $labelsByContainer = [];
                foreach ($labelRows as $row) {
                    $containerId = (int) $row->id;
                    $partai = trim((string) ($row->partai ?? ''));
                    if ($partai !== '') {
                        $labelsByContainer[$containerId] = $partai;
                        continue;
                    }
                    $containerNo = trim((string) ($row->container_no ?? ''));
                    $labelsByContainer[$containerId] = $containerNo !== '' ? $containerNo : '#'.$containerId;
                }

                foreach ($missingContainers as $barangId) {
                    $firstContainerId = self::$containerIdsByBarang[$barangId][0] ?? null;
                    self::$partaiLabelCache[$barangId] = $firstContainerId !== null
                        ? ($labelsByContainer[$firstContainerId] ?? null)
                        : null;
                }
            } else {
                foreach ($missingContainers as $barangId) {
                    self::$partaiLabelCache[$barangId] = null;
                }
            }
        }

        $allInvoiceIds = [];
        foreach ($barangIds as $barangId) {
            foreach (self::$invoiceIdsByBarang[$barangId] ?? [] as $invoiceId) {
                $allInvoiceIds[$invoiceId] = true;
            }
        }

        // Expand to partai peer invoices so releasable checks stay cache-hit.
        $peerBarangIds = [];
        foreach ($barangIds as $barangId) {
            foreach (self::$containerIdsByBarang[$barangId] ?? [] as $containerId) {
                foreach (self::$barangIdsByContainer[$containerId] ?? [] as $peerId) {
                    $peerBarangIds[$peerId] = true;
                }
            }
        }
        $peerBarangIdList = array_keys($peerBarangIds);
        if ($peerBarangIdList !== []) {
            $stillMissingPeers = array_values(array_filter(
                $peerBarangIdList,
                static fn (int $id): bool => ! array_key_exists($id, self::$invoiceIdsByBarang),
            ));
            if ($stillMissingPeers !== []) {
                self::warmCachesForBarangIds($stillMissingPeers);
            }
            foreach ($peerBarangIdList as $peerId) {
                foreach (self::$invoiceIdsByBarang[$peerId] ?? [] as $invoiceId) {
                    $allInvoiceIds[$invoiceId] = true;
                }
            }
        }

        $invoiceIdList = array_keys($allInvoiceIds);
        $missingPaid = array_values(array_filter(
            $invoiceIdList,
            static fn (int $id): bool => ! array_key_exists($id, self::$invoicePaidCache),
        ));
        if ($missingPaid === []) {
            return;
        }

        $statusRows = DB::table('invoices')
            ->whereIn('id', $missingPaid)
            ->get(['id', 'status', 'grand_total']);

        $paidByStatus = [];
        $needsPaymentCheck = [];
        foreach ($statusRows as $row) {
            $invoiceId = (int) $row->id;
            $status = (string) $row->status;
            if ($status === 'paid') {
                self::$invoicePaidCache[$invoiceId] = true;
                $paidByStatus[$invoiceId] = true;
            } elseif ($status === 'cancelled') {
                self::$invoicePaidCache[$invoiceId] = false;
            } else {
                $needsPaymentCheck[] = $invoiceId;
            }
        }

        foreach ($missingPaid as $invoiceId) {
            if (! array_key_exists($invoiceId, self::$invoicePaidCache)) {
                $needsPaymentCheck[] = $invoiceId;
            }
        }
        $needsPaymentCheck = array_values(array_unique($needsPaymentCheck));

        if ($needsPaymentCheck !== []) {
            $fullyPaidIds = Invoice::query()
                ->whereIn('id', $needsPaymentCheck)
                ->fullyPaid()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $fullyPaidLookup = array_fill_keys($fullyPaidIds, true);
            foreach ($needsPaymentCheck as $invoiceId) {
                self::$invoicePaidCache[$invoiceId] = isset($fullyPaidLookup[$invoiceId]);
            }
        }

        unset($paidByStatus);
    }

    public static function syncForBarang(
        Barang $barang,
        bool $syncIncentive = true,
        ?array $breakdown = null,
    ): ?SalesCommission {
        if (! self::schemaReady()) {
            return null;
        }

        $breakdown ??= SalesRevenueCalculator::earningsBreakdown($barang);
        if ($breakdown === null) {
            SalesCommission::query()->where('barang_id', $barang->id)->delete();

            return null;
        }

        SalesRevenueCalculator::ensureCompanyLoyaltySnapshot($barang);

        $invoicePaid = self::isBarangCommissionReleasable((int) $barang->id);
        $existing = SalesCommission::query()->where('barang_id', $barang->id)->first();

        $status = $invoicePaid
            ? SalesCommission::STATUS_FIXED
            : SalesCommission::STATUS_WAITING;

        $companyProfitAmount = $existing !== null && $existing->isSettled()
            ? (float) $existing->company_profit_amount
            : $breakdown['company_profit'];

        $payload = [
            'sales_id' => $breakdown['sales_id'],
            'supervisor_id' => $breakdown['supervisor_id'],
            'total_cbm' => $breakdown['total_cbm'],
            'billable_base' => $breakdown['omset'],
            'company_profit_amount' => $companyProfitAmount,
            'sales_commission_base' => $breakdown['sales_commission_base'],
            'cost_subtotal' => $breakdown['cost_subtotal'],
            'markup_amount' => $breakdown['markup'],
            'supervisor_amount' => $breakdown['supervisor_amount'],
            'commission_rate' => $breakdown['commission_rate'],
            'estimated_amount' => $breakdown['sales_commission'],
            'status' => $status,
        ];

        if ($invoicePaid) {
            $payload['confirmed_amount'] = $breakdown['sales_commission'];
            $payload['settled_at'] = $existing?->settled_at ?? now();
            $payload['confirmed_at'] = $existing?->confirmed_at ?? now();
        } elseif ($existing !== null && in_array($existing->status, [
            SalesCommission::STATUS_CONFIRMED,
            SalesCommission::STATUS_CAIR,
            SalesCommission::STATUS_FIXED,
        ], true)) {
            // Tetap fixed jika sudah pernah lunas (jangan turunkan ke estimasi).
            $payload['status'] = $existing->status === SalesCommission::STATUS_CAIR
                ? SalesCommission::STATUS_CAIR
                : SalesCommission::STATUS_FIXED;
            $payload['confirmed_amount'] = $existing->confirmed_amount ?? $breakdown['sales_commission'];
            $payload['settled_at'] = $existing->settled_at;
            $payload['confirmed_at'] = $existing->confirmed_at;
        } else {
            $payload['confirmed_amount'] = null;
            $payload['settled_at'] = null;
            $payload['confirmed_at'] = null;
            $payload['confirmed_by'] = null;
            $payload['paid_at'] = null;
        }

        $commission = SalesCommission::query()->updateOrCreate(
            ['barang_id' => $barang->id],
            $payload,
        );

        if ($syncIncentive) {
            SalesIncentiveService::syncForBarang($barang);
        }

        return $commission;
    }

    public static function syncForInvoice(Invoice $invoice): void
    {
        if (! self::schemaReady() || $invoice->status !== 'paid') {
            return;
        }

        $barangIds = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->pluck('barang_id')
            ->map(static fn ($id) => (int) $id);

        $syncIds = collect();
        foreach ($barangIds as $barangId) {
            $syncIds = $syncIds
                ->merge(self::barangIdsInSamePartai($barangId))
                ->push($barangId);
        }

        $uniqueIds = $syncIds->unique()->values()->all();
        self::warmCachesForBarangIds(array_map(static fn ($id): int => (int) $id, $uniqueIds));

        foreach ($uniqueIds as $barangId) {
            $barang = Barang::query()->find($barangId);
            if ($barang !== null) {
                self::syncForBarang($barang);
            }
        }
    }

    public static function isBarangInvoicePaid(int $barangId): bool
    {
        if (array_key_exists($barangId, self::$barangInvoicePaidCache)) {
            return self::$barangInvoicePaidCache[$barangId];
        }

        $invoiceIds = self::invoiceIdsForBarang($barangId);
        if ($invoiceIds === []) {
            return self::$barangInvoicePaidCache[$barangId] = false;
        }

        foreach ($invoiceIds as $invoiceId) {
            if (self::isInvoicePaid($invoiceId)) {
                return self::$barangInvoicePaidCache[$barangId] = true;
            }
        }

        return self::$barangInvoicePaidCache[$barangId] = false;
    }

    /** True if invoice status is paid OR approved payments cover grand_total (finance source of truth). */
    public static function isInvoicePaid(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        if (array_key_exists($invoiceId, self::$invoicePaidCache)) {
            return self::$invoicePaidCache[$invoiceId];
        }

        $status = DB::table('invoices')->where('id', $invoiceId)->value('status');
        if ($status === 'paid') {
            return self::$invoicePaidCache[$invoiceId] = true;
        }

        if ($status === 'cancelled') {
            return self::$invoicePaidCache[$invoiceId] = false;
        }

        return self::$invoicePaidCache[$invoiceId] = Invoice::query()->whereKey($invoiceId)->fullyPaid()->exists();
    }

    /** Komisi net fix hanya jika invoice pengiriman lunas dan seluruh invoice partai lunas. */
    public static function isBarangCommissionReleasable(int $barangId): bool
    {
        if (array_key_exists($barangId, self::$releasableCache)) {
            return self::$releasableCache[$barangId];
        }

        if (! self::isBarangInvoicePaid($barangId)) {
            return self::$releasableCache[$barangId] = false;
        }

        $containerIds = self::containerIdsForBarang($barangId);
        if ($containerIds === []) {
            return self::$releasableCache[$barangId] = true;
        }

        foreach ($containerIds as $containerId) {
            if (! self::isPartaiAllInvoicesPaid($containerId)) {
                return self::$releasableCache[$barangId] = false;
            }
        }

        return self::$releasableCache[$barangId] = true;
    }

    public static function isPartaiAllInvoicesPaid(int $containerId): bool
    {
        if (array_key_exists($containerId, self::$partaiAllPaidCache)) {
            return self::$partaiAllPaidCache[$containerId];
        }

        $barangIds = self::$barangIdsByContainer[$containerId]
            ?? DB::table('container_barangs')
                ->where('container_id', $containerId)
                ->pluck('barang_id')
                ->map(static fn ($id) => (int) $id)
                ->all();

        self::$barangIdsByContainer[$containerId] = $barangIds;

        if ($barangIds === []) {
            return self::$partaiAllPaidCache[$containerId] = true;
        }

        $invoiceIds = [];
        foreach ($barangIds as $barangId) {
            foreach (self::invoiceIdsForBarang((int) $barangId) as $invoiceId) {
                $invoiceIds[$invoiceId] = true;
            }
        }
        $invoiceIdList = array_keys($invoiceIds);

        if ($invoiceIdList === []) {
            return self::$partaiAllPaidCache[$containerId] = false;
        }

        foreach ($invoiceIdList as $invoiceId) {
            if (! self::isInvoicePaid($invoiceId)) {
                return self::$partaiAllPaidCache[$containerId] = false;
            }
        }

        return self::$partaiAllPaidCache[$containerId] = true;
    }

    /** @return list<int> */
    public static function invoiceIdsForBarang(int $barangId): array
    {
        if (array_key_exists($barangId, self::$invoiceIdsByBarang)) {
            return self::$invoiceIdsByBarang[$barangId];
        }

        return self::$invoiceIdsByBarang[$barangId] = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoice_items.barang_id', $barangId)
            ->whereNotIn('invoices.status', ['cancelled'])
            ->distinct()
            ->pluck('invoices.id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public static function containerIdsForBarang(int $barangId): array
    {
        if (array_key_exists($barangId, self::$containerIdsByBarang)) {
            return self::$containerIdsByBarang[$barangId];
        }

        return self::$containerIdsByBarang[$barangId] = DB::table('container_barangs')
            ->where('barang_id', $barangId)
            ->pluck('container_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public static function barangIdsInSamePartai(int $barangId): array
    {
        $containerIds = self::containerIdsForBarang($barangId);
        if ($containerIds === []) {
            return [];
        }

        $ids = [];
        foreach ($containerIds as $containerId) {
            if (! array_key_exists($containerId, self::$barangIdsByContainer)) {
                self::$barangIdsByContainer[$containerId] = DB::table('container_barangs')
                    ->where('container_id', $containerId)
                    ->pluck('barang_id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();
            }
            foreach (self::$barangIdsByContainer[$containerId] as $peerId) {
                $ids[$peerId] = true;
            }
        }

        return array_keys($ids);
    }

    public static function resolvePartaiLabel(int $barangId): ?string
    {
        if (array_key_exists($barangId, self::$partaiLabelCache)) {
            return self::$partaiLabelCache[$barangId];
        }

        $containerId = self::containerIdsForBarang($barangId)[0] ?? null;
        if ($containerId === null) {
            return self::$partaiLabelCache[$barangId] = null;
        }

        $row = DB::table('containers')
            ->where('id', $containerId)
            ->first(['partai', 'container_no']);

        if ($row === null) {
            return self::$partaiLabelCache[$barangId] = null;
        }

        $partai = trim((string) ($row->partai ?? ''));
        if ($partai !== '') {
            return self::$partaiLabelCache[$barangId] = $partai;
        }

        $containerNo = trim((string) ($row->container_no ?? ''));

        return self::$partaiLabelCache[$barangId] = $containerNo !== '' ? $containerNo : '#'.$containerId;
    }
}
