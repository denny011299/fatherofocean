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
    public static function schemaReady(): bool
    {
        return Schema::hasTable('sales_commissions')
            && Schema::hasColumn('sales_commissions', 'company_profit_amount');
    }

    public static function syncForBarang(Barang $barang): ?SalesCommission
    {
        if (! self::schemaReady()) {
            return null;
        }

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        if ($breakdown === null) {
            SalesCommission::query()->where('barang_id', $barang->id)->delete();

            return null;
        }

        $invoicePaid = self::isBarangCommissionReleasable($barang->id);
        $existing = SalesCommission::query()->where('barang_id', $barang->id)->first();

        $status = $invoicePaid
            ? SalesCommission::STATUS_FIXED
            : SalesCommission::STATUS_WAITING;

        $payload = [
            'sales_id' => $breakdown['sales_id'],
            'supervisor_id' => $breakdown['supervisor_id'],
            'total_cbm' => $breakdown['total_cbm'],
            'billable_base' => $breakdown['omset'],
            'company_profit_amount' => $breakdown['company_profit'],
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

        SalesIncentiveService::syncForBarang($barang);

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

        foreach ($syncIds->unique()->values() as $barangId) {
            $barang = Barang::query()->find($barangId);
            if ($barang !== null) {
                self::syncForBarang($barang);
            }
        }
    }

    public static function isBarangInvoicePaid(int $barangId): bool
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoice_items.barang_id', $barangId)
            ->where('invoices.status', 'paid')
            ->exists();
    }

    /** Komisi net fix hanya jika invoice pengiriman lunas dan seluruh invoice partai lunas. */
    public static function isBarangCommissionReleasable(int $barangId): bool
    {
        if (! self::isBarangInvoicePaid($barangId)) {
            return false;
        }

        $containerIds = self::containerIdsForBarang($barangId);
        if ($containerIds === []) {
            return true;
        }

        foreach ($containerIds as $containerId) {
            if (! self::isPartaiAllInvoicesPaid($containerId)) {
                return false;
            }
        }

        return true;
    }

    public static function isPartaiAllInvoicesPaid(int $containerId): bool
    {
        $barangIds = DB::table('container_barangs')
            ->where('container_id', $containerId)
            ->pluck('barang_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($barangIds === []) {
            return true;
        }

        $invoiceIds = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_items.barang_id', $barangIds)
            ->whereNotIn('invoices.status', ['cancelled'])
            ->distinct()
            ->pluck('invoices.id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($invoiceIds === []) {
            return false;
        }

        $unpaidCount = DB::table('invoices')
            ->whereIn('id', $invoiceIds)
            ->where('status', '!=', 'paid')
            ->count();

        return $unpaidCount === 0;
    }

    /** @return list<int> */
    public static function containerIdsForBarang(int $barangId): array
    {
        return DB::table('container_barangs')
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

        return DB::table('container_barangs')
            ->whereIn('container_id', $containerIds)
            ->pluck('barang_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function resolvePartaiLabel(int $barangId): ?string
    {
        $containerId = self::containerIdsForBarang($barangId)[0] ?? null;
        if ($containerId === null) {
            return null;
        }

        $row = DB::table('containers')
            ->where('id', $containerId)
            ->first(['partai', 'container_no']);

        if ($row === null) {
            return null;
        }

        $partai = trim((string) ($row->partai ?? ''));
        if ($partai !== '') {
            return $partai;
        }

        $containerNo = trim((string) ($row->container_no ?? ''));

        return $containerNo !== '' ? $containerNo : '#'.$containerId;
    }
}
