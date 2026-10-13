<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OmsetExportDataBuilder
{
    /**
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, float|int>, 2: string, 3: string}
     */
    public static function buildPreview(
        string $periodType,
        int $year,
        ?int $month,
        ?int $week,
        ?User $user = null,
    ): array {
        $periodLabel = self::periodLabel($periodType);
        $invoiceIds = self::invoiceIdsForPeriod($periodType, $year, $month, $week, $user);
        $periodFormat = OmsetInvoiceQuery::omsetPeriodSelectSql($periodType);

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('barangs', 'invoice_items.barang_id', '=', 'barangs.id')
            ->whereIn('invoices.id', $invoiceIds)
            ->select(
                DB::raw("{$periodFormat} as omset_period"),
                DB::raw("COALESCE(barangs.marking, 'Tanpa Marking') as marking"),
                DB::raw('COUNT(DISTINCT barangs.id) as shipment_count'),
                DB::raw('SUM(invoice_items.cbm) as total_cbm'),
                DB::raw('SUM(barangs.total_gross_weight) as total_gw'),
                DB::raw('SUM(invoice_items.line_total) as omset'),
            )
            ->groupBy('omset_period', 'marking')
            ->orderBy('omset_period')
            ->orderByDesc('omset')
            ->get()
            ->map(static fn ($row) => [
                'period' => (string) $row->omset_period,
                'marking' => $row->marking,
                'shipment_count' => (int) $row->shipment_count,
                'total_cbm' => (float) $row->total_cbm,
                'total_gw' => (float) $row->total_gw,
                'omset' => (float) $row->omset,
            ]);

        $totals = [
            'shipment_count' => $rows->sum('shipment_count'),
            'total_cbm' => $rows->sum('total_cbm'),
            'total_gw' => $rows->sum('total_gw'),
            'omset' => $rows->sum('omset'),
        ];

        return [$rows, $totals, $periodLabel, self::rangeLabel($periodType, $year, $month)];
    }

    /**
     * @return array{
     *     groups: list<array{
     *         marketing_name: string,
     *         rows: list<array{
     *             serial_no: string,
     *             marking: string,
     *             item: string,
     *             cbm: float,
     *             kg: ?float,
     *             modal_cbm_price: float,
     *             modal_kg_price: float,
     *             sell_cbm_price: float,
     *             sell_kg_price: float,
     *             keterangan: string,
     *             catatan: string,
     *             payment_status: string,
     *             excess_kg: float,
     *             method: string,
     *         }>,
     *     }>,
     *     partai_label: ?string,
     *     company_fixed_amount: float,
     *     company_name: string,
     * }
     */
    public static function build(
        string $periodType,
        int $year,
        ?int $month,
        ?int $week,
        ?User $user = null,
    ): array {
        $invoiceIds = self::invoiceIdsForPeriod($periodType, $year, $month, $week, $user);

        if ($invoiceIds->isEmpty()) {
            return [
                'groups' => [],
                'partai_label' => null,
                'company_fixed_amount' => CompanyRevenueSettings::fixedAmount(),
                'company_name' => (string) config('app.name', 'Ocean Link'),
            ];
        }

        $barangToInvoice = DB::table('invoice_items')
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['invoice_id', 'barang_id'])
            ->groupBy('barang_id')
            ->map(static fn (Collection $rows) => (int) $rows->first()->invoice_id);

        $barangIds = $barangToInvoice->keys()
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->get()
            ->keyBy('id');

        $barangs = Barang::query()
            ->whereIn('id', $barangIds)
            ->with(['products' => static fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('marking')
            ->orderBy('no_index')
            ->get();

        $marketingBuckets = [];

        foreach ($barangs as $barang) {
            $invoiceId = (int) ($barangToInvoice[$barang->id] ?? 0);
            $invoice = $invoices->get($invoiceId);

            $salesId = SalesRevenueCalculator::resolveSalesId($barang);
            $marketingName = $salesId !== null
                ? (string) (User::query()->whereKey($salesId)->value('name') ?? 'Tanpa Marketing')
                : 'Tanpa Marketing';
            $marketingBuckets[$marketingName] ??= [];
            $marketingBuckets[$marketingName][] = self::mapBarangRow(
                $barang,
                $invoice instanceof Invoice ? $invoice : null,
            );
        }

        ksort($marketingBuckets);

        $groups = [];
        foreach ($marketingBuckets as $marketingName => $rows) {
            $groups[] = [
                'marketing_name' => $marketingName,
                'rows' => $rows,
            ];
        }

        $partaiLabel = null;
        foreach ($barangs as $barang) {
            $label = ShipmentEarningsSettlement::resolvePartaiLabel((int) $barang->id);
            if ($label !== null && $label !== '') {
                $partaiLabel ??= $label;
                break;
            }
        }

        return [
            'groups' => $groups,
            'partai_label' => $partaiLabel,
            'company_fixed_amount' => CompanyRevenueSettings::fixedAmount(),
            'company_name' => (string) config('app.name', 'Ocean Link'),
        ];
    }

    /** @return Collection<int, int> */
    private static function invoiceIdsForPeriod(
        string $periodType,
        int $year,
        ?int $month,
        ?int $week,
        ?User $user,
    ): Collection {
        $query = Invoice::query();
        OmsetInvoiceQuery::applyPeriodFilter($query, $periodType, $year, $month, $week);

        if ($user !== null && Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictInvoicesQuery($query, $user);
        }

        return $query->pluck('id');
    }

    /**
     * @return array{
     *     serial_no: string,
     *     marking: string,
     *     item: string,
     *     cbm: float,
     *     kg: ?float,
     *     modal_cbm_price: float,
     *     modal_kg_price: float,
     *     sell_cbm_price: float,
     *     sell_kg_price: float,
     *     keterangan: string,
     *     catatan: string,
     *     payment_status: string,
     *     excess_kg: float,
     *     method: string,
     * }
     */
    private static function mapBarangRow(Barang $barang, ?Invoice $invoice): array
    {
        $pricing = BarangShippingPricing::calculate($barang);
        $modalRates = BarangCostPricing::modalRatesForBarang($barang);
        $method = (string) ($pricing['method'] ?? BarangShippingPricing::METHOD_CBM);
        $billableCbm = (float) ($pricing['billable_cbm'] ?? $barang->total_cbm);
        $excessKg = (float) ($pricing['excess_kg'] ?? 0);
        $grossWeight = (float) $barang->total_gross_weight;

        $kg = match ($method) {
            BarangShippingPricing::METHOD_WEIGHT_SPLIT => $grossWeight > 0 ? $grossWeight : null,
            BarangShippingPricing::METHOD_WEIGHT => $grossWeight > 0 ? $grossWeight : null,
            default => null,
        };

        return [
            'serial_no' => trim((string) ($barang->no_index ?? '')) ?: '-',
            'marking' => trim((string) ($barang->marking ?? '')) ?: '-',
            'item' => self::primaryItemLabel($barang),
            'cbm' => CbmFormat::truncate(max(0, $billableCbm)),
            'kg' => $kg,
            'modal_cbm_price' => max(0, (float) $modalRates['volume_price']),
            'modal_kg_price' => max(0, (float) $modalRates['gw_price']),
            'sell_cbm_price' => max(0, (float) $barang->price_per_cbm),
            'sell_kg_price' => max(0, (float) $barang->weight_price),
            'keterangan' => self::keteranganLabel($method, $excessKg),
            'catatan' => trim((string) ($barang->notes ?? '')),
            'payment_status' => $invoice instanceof Invoice
                ? OmsetInvoiceQuery::paymentStatusLabel($invoice)
                : 'Belum Bayar',
            'excess_kg' => $excessKg,
            'method' => $method,
        ];
    }

    private static function primaryItemLabel(Barang $barang): string
    {
        $product = $barang->products
            ->sortBy('sort_order')
            ->first(static fn (BarangProduct $row): bool => $row->parent_id === null)
            ?? $barang->products->sortBy('sort_order')->first();

        if ($product === null) {
            return '';
        }

        return trim((string) ($product->product_name ?? ''));
    }

    private static function keteranganLabel(string $method, float $excessKg): string
    {
        return match ($method) {
            BarangShippingPricing::METHOD_WEIGHT_SPLIT => $excessKg > 0
                ? 'Over - '.self::formatQty($excessKg)
                : 'CBM',
            BarangShippingPricing::METHOD_WEIGHT => 'KG',
            default => 'CBM',
        };
    }

    private static function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format(max(0, $value), 2, '.', ''), '0'), '.');
    }

    private static function periodLabel(string $periodType): string
    {
        return match ($periodType) {
            'mingguan' => 'Mingguan',
            'bulanan' => 'Bulanan',
            'tahunan' => 'Tahunan',
            default => 'Bulanan',
        };
    }

    private static function rangeLabel(string $periodType, int $year, ?int $month): string
    {
        return match ($periodType) {
            'mingguan' => $month
                ? Carbon::create($year, $month)->translatedFormat('F Y')
                : (string) $year,
            'bulanan' => $month
                ? Carbon::create($year, $month)->translatedFormat('F Y')
                : "Tahun {$year}",
            'tahunan' => "Tahun {$year}",
            default => "Tahun {$year}",
        };
    }
}
