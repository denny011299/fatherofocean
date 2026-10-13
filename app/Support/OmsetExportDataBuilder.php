<?php



declare(strict_types=1);



namespace App\Support;



use App\Exports\BarangListProductsExport;
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
        OmsetFilters $filters,
        ?User $user = null,
    ): array {
        $periodLabel = 'Tanggal Masuk';
        $barangIds = self::barangIdsForFilters($filters, $user);
        $periodFormat = $filters->previewPeriodSelectSql();

        if ($barangIds->isEmpty()) {
            return [collect(), [
                'shipment_count' => 0,
                'total_cbm' => 0.0,
                'total_gw' => 0.0,
                'omset' => 0.0,
            ], $periodLabel, $filters->filterTitle()];
        }

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('barangs', 'invoice_items.barang_id', '=', 'barangs.id')
            ->whereIn('barangs.id', $barangIds)

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



        return [$rows, $totals, $periodLabel, $filters->filterTitle()];
    }

    /**

     * @return array{

     *     groups: list<array{

     *         marketing_name: string,

     *         sales_id: ?int,

     *         commission_rate: float,

     *         rows: list<array<string, mixed>>,

     *     }>,

     *     partai_label: ?string,

     *     company_fixed_amount: float,

     *     company_name: string,

     * }

     */

    public static function build(
        OmsetFilters $filters,
        ?User $user = null,
    ): array {

        $barangIds = self::barangIdsForFilters($filters, $user);

        if ($barangIds->isEmpty()) {

            return [

                'groups' => [],

                'partai_label' => null,

                'company_fixed_amount' => CompanyRevenueSettings::fixedAmount(),

                'company_name' => CompanyRevenueSettings::exportCompanyName(),

                'category_reference' => CompanyRevenueSettings::exportCategoryReferenceRows(),

            ];

        }



        $barangToInvoice = DB::table('invoice_items')

            ->whereIn('barang_id', $barangIds)

            ->get(['invoice_id', 'barang_id'])

            ->groupBy('barang_id')

            ->map(static fn (Collection $rows) => (int) $rows->first()->invoice_id);



        $invoiceIds = $barangToInvoice->values()

            ->map(static fn (int $invoiceId): int => $invoiceId)

            ->unique()

            ->values();

        $invoices = $invoiceIds->isEmpty()

            ? collect()

            : Invoice::query()

                ->whereIn('id', $invoiceIds)

                ->with(['items:id,invoice_id,barang_id,line_total'])

                ->get()

                ->keyBy('id');



        $barangs = Barang::query()

            ->whereIn('id', $barangIds)

            ->with([

                'products' => static fn ($q) => $q->orderBy('sort_order'),

                'additionalPrices' => static fn ($q) => $q->orderBy('sort_order'),

            ])

            ->orderBy('marking')

            ->orderBy('no_index')

            ->get();



        /** @var array<string, array{sales_id: ?int, rows: list<array<string, mixed>>}> $marketingBuckets */

        $marketingBuckets = [];



        foreach ($barangs as $barang) {

            $invoiceId = (int) ($barangToInvoice[$barang->id] ?? 0);

            $invoice = $invoices->get($invoiceId);



            $salesId = SalesRevenueCalculator::resolveSalesId($barang);

            $marketingName = $salesId !== null
                ? (string) (User::query()->whereKey($salesId)->value('name') ?? 'Tanpa Sales')
                : 'Tanpa Sales';



            $marketingBuckets[$marketingName] ??= [

                'sales_id' => $salesId,

                'rows' => [],

            ];

            $marketingBuckets[$marketingName]['rows'][] = self::mapBarangRow(

                $barang,

                $invoice instanceof Invoice ? $invoice : null,

                $salesId,

            );

        }



        ksort($marketingBuckets);



        $groups = [];

        foreach ($marketingBuckets as $marketingName => $bucket) {

            $salesId = $bucket['sales_id'];

            $groups[] = [

                'marketing_name' => $marketingName,

                'sales_id' => $salesId,

                'commission_rate' => SalesCommissionSettings::rateForSalesId($salesId),

                'rows' => $bucket['rows'],

                'company_category_breakdown' => self::companyCategoryBreakdown($bucket['rows']),

            ];

        }



        $partaiLabel = null;
        if ($filters->partai !== null && $filters->partai !== '' && $filters->partai !== 'belum_di_muat') {
            $partaiLabel = $filters->partai;
        } else {
            foreach ($barangs as $barang) {
                $label = ShipmentEarningsSettlement::resolvePartaiLabel((int) $barang->id);
                if ($label !== null && $label !== '') {
                    $partaiLabel ??= $label;
                    break;
                }
            }
        }



        return [

            'groups' => $groups,

            'partai_label' => $partaiLabel,

            'company_fixed_amount' => CompanyRevenueSettings::fixedAmount(),

            'company_name' => CompanyRevenueSettings::exportCompanyName(),

            'category_reference' => CompanyRevenueSettings::exportCategoryReferenceRows(),

        ];

    }



    /** @return Collection<int, int> */

    private static function barangIdsForFilters(
        OmsetFilters $filters,
        ?User $user,
    ): Collection {

        $query = Barang::query();

        $filters->applyToBarangQuery($query);



        if ($user !== null && Marking::userHasTeamMarkingScope($user)) {

            Marking::restrictBarangsQuery($query, $user);

        }



        return $query->pluck('id');

    }



    /**
     * @return array<string, mixed>
     */
    private static function mapBarangRow(Barang $barang, ?Invoice $invoice, ?int $salesId): array
    {
        $modalRates = BarangCostPricing::modalRatesForBarang($barang);
        $sellRates = BarangShippingPricing::resolveSellRates($barang);
        $weightThresholdKg = BarangShippingPricing::normalizeThresholdPerCbmKg(
            (float) ($sellRates['weight_threshold_kg'] ?? 0),
        );
        $minimumChargeCbm = CbmFormat::truncate(
            BarangShippingPricing::resolveMinimumChargeCbm($barang),
        );

        // Excel Omset: CBM baris = volume aktual; min charge + overweight di level Total CBM (marking).
        $actualCbm = CbmFormat::truncate(max(0, (float) $barang->total_cbm));

        $grossWeight = (float) $barang->total_gross_weight;

        $kg = $grossWeight > 0 ? $grossWeight : null;

        // Jual: field barang dulu, fallback Volume/GW Price dari Marking (resolveSellRates).
        $sellCbmPrice = max(0, (float) ($sellRates['price_per_cbm'] ?? 0));

        $sellKgPrice = max(0, (float) ($sellRates['weight_price'] ?? 0));



        $supervisorId = SalesRevenueCalculator::resolveSupervisorId($barang, $salesId);

        // PIC dari master user (atasan sales) — jangan isi nama sales di sini.
        $marketingStaff = '';
        if ($supervisorId !== null) {
            $marketingStaff = (string) (User::query()->whereKey($supervisorId)->value('name') ?? '');
        }

        $salesName = $salesId !== null
            ? (string) (User::query()->whereKey($salesId)->value('name') ?? '')
            : '';

        $additionalFee = max(0, (float) ($barang->extra_charge ?? 0));

        $additionalFeeNote = self::additionalFeeNote($barang, $additionalFee);



        return [

            'serial_no' => trim((string) ($barang->no_index ?? '')) ?: '-',

            'tanggal_masuk' => $barang->tanggal_masuk instanceof Carbon

                ? $barang->tanggal_masuk->locale(app()->getLocale())->translatedFormat('d M Y')

                : '',

            'tanggal_masuk_excel' => $barang->tanggal_masuk instanceof Carbon

                ? \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($barang->tanggal_masuk->startOfDay())

                : null,

            'marking' => trim((string) ($barang->marking ?? '')) ?: '-',

            'item' => self::primaryItemLabel($barang),

            'kategori' => self::kategoriLabel($barang),

            'company_loyalty_amount' => SalesRevenueCalculator::resolveCompanyLoyaltyAmount($barang),

            // CBM aktual per index; Total CBM / overweight / min charge di OmsetSpreadsheetBuilder (total-first).
            'cbm' => $actualCbm,
            'cbm_actual' => $actualCbm,
            'minimum_charge_cbm' => $minimumChargeCbm,

            'kg' => $kg,

            'modal_cbm_price' => max(0, (float) $modalRates['volume_price']),

            'modal_kg_price' => max(0, (float) $modalRates['gw_price']),

            'sell_cbm_price' => $sellCbmPrice,

            'sell_kg_price' => $sellKgPrice,

            'weight_threshold_kg' => $weightThresholdKg,

            'additional_fee' => $additionalFee,

            'additional_fee_note' => $additionalFeeNote,

            'commission_rate' => SalesCommissionSettings::rateForSalesId($salesId),

            'marketing_staff' => $marketingStaff,

            'sales_name' => $salesName,

            'payment_status' => $invoice instanceof Invoice

                ? self::paymentStatusLabel($invoice)

                : 'belum lunas',

            'status_update' => self::statusUpdateLabel((string) $barang->status),

            'partai' => ShipmentEarningsSettlement::resolvePartaiLabel((int) $barang->id) ?? '',

            'catatan' => trim((string) ($barang->notes ?? '')),

        ];

    }



    private static function paymentStatusLabel(Invoice $invoice): string

    {

        return Invoice::query()->whereKey($invoice->id)->fullyPaid()->exists()

            ? 'Sudah Lunas'

            : 'belum lunas';

    }



    private static function statusUpdateLabel(string $status): string

    {

        return match (Barang::normalizeShipmentStatus($status)) {

            Barang::STATUS_BONGKAR => 'Bongkaran',

            Barang::STATUS_STUFFING => 'Stuffing',

            Barang::STATUS_INBOUND => 'Inbound',

            Barang::STATUS_ETD => 'ETD',

            Barang::STATUS_ETA => 'ETA',

            Barang::STATUS_DITARIK_KEMBALI => 'Ditarik Kembali',

            default => ucfirst($status),

        };

    }



    private static function additionalFeeNote(Barang $barang, float $additionalFee): string

    {

        if ($additionalFee <= 0) {

            return '';

        }



        $firstPrice = $barang->additionalPrices->first();

        if ($firstPrice !== null) {

            $name = trim((string) ($firstPrice->name ?? ''));

            if ($name !== '' && $name !== '-') {

                return $name;

            }

        }



        return '';

    }



    private static function kategoriLabel(Barang $barang): string
    {
        return BarangListProductsExport::resolveKategoriLabel($barang->cbm_type);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{kategori: string, shipment_count: int, profit_per_shipment: float, total_amount: float}>
     */
    private static function companyCategoryBreakdown(array $rows): array
    {
        /** @var array<string, array{kategori: string, shipment_count: int, total_amount: float}> $byKategori */
        $byKategori = [];

        foreach ($rows as $row) {
            $kategori = trim((string) ($row['kategori'] ?? ''));
            if ($kategori === '' || $kategori === '-') {
                continue;
            }

            $loyalty = (float) ($row['company_loyalty_amount'] ?? 0);
            if ($loyalty <= 0) {
                continue;
            }

            $key = mb_strtolower($kategori);
            $byKategori[$key] ??= [
                'kategori' => $kategori,
                'shipment_count' => 0,
                'total_amount' => 0.0,
            ];
            $byKategori[$key]['shipment_count']++;
            $byKategori[$key]['total_amount'] += $loyalty;
        }

        if ($byKategori === []) {
            return [];
        }

        $breakdown = [];
        foreach ($byKategori as $bucket) {
            $kategori = $bucket['kategori'];
            $totalAmount = round($bucket['total_amount'], 0);
            if ($totalAmount <= 0) {
                continue;
            }

            $shipmentCount = $bucket['shipment_count'];
            $breakdown[] = [
                'kategori' => $kategori,
                'shipment_count' => $shipmentCount,
                'total_amount' => $totalAmount,
                'profit_per_shipment' => $shipmentCount > 0
                    ? round($totalAmount / $shipmentCount, 0)
                    : 0.0,
            ];
        }

        usort(
            $breakdown,
            static fn (array $left, array $right): int => strcmp($left['kategori'], $right['kategori']),
        );

        return $breakdown;
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


