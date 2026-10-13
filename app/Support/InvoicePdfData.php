<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Claim;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Marking;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class InvoicePdfData
{
    /**
     * Min charge + overweight dari TOTAL CBM/kg invoice untuk semua partai numerik yang sudah dimuat.
     * (Cutoff lama 724 diganti: partai lama yang belum ditagih — mis. 434 — juga harus total-first.)
     * Kiriman belum dimuat (tanpa partai numerik) tetap rumus per index.
     */
    public const INVOICE_LEVEL_MIN_OVERWEIGHT_FROM_PARTAI = 1;

    /**
     * @return array<string, mixed>
     */
    public static function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'customer',
            'payments',
            'claims',
            'items.barang' => static fn ($q) => $q->with([
                'products' => static fn ($pq) => $pq->orderBy('sort_order'),
                'additionalPrices' => static fn ($aq) => $aq->orderBy('sort_order'),
                'containers:id,partai',
            ]),
        ]);

        $items = $invoice->items
            ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
            ->values();

        $barangs = $items
            ->map(static fn (InvoiceItem $item) => $item->barang)
            ->filter()
            ->values();

        self::loadBarangRelations($barangs);

        // Heal invoice draft/sent yang ketinggalan setelah Instant Pricing ACC (extra/harga).
        if ($invoice->exists) {
            foreach ($barangs as $barang) {
                $barang->syncOpenInvoicePricingFromBarang();
            }
            $invoice->refresh()->load([
                'customer',
                'payments',
                'claims',
                'items.barang' => static fn ($q) => $q->with([
                    'products' => static fn ($pq) => $pq->orderBy('sort_order'),
                    'additionalPrices' => static fn ($aq) => $aq->orderBy('sort_order'),
                    'containers:id,partai',
                ]),
            ]);
            $items = $invoice->items
                ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
                ->values();
            $barangs = $items
                ->map(static fn (InvoiceItem $item) => $item->barang)
                ->filter()
                ->values();
            self::loadBarangRelations($barangs);
        }

        $promoNote = (float) $invoice->promo_discount > 0
            ? 'Promo '.$invoice->promo_name.' ('.$invoice->promoValueLabel().'): -'.self::formatRp((float) $invoice->promo_discount)
            : null;
        $claimDisplayTotal = self::resolveClaimDisplayTotal($invoice);
        $claimNote = $claimDisplayTotal > 0.009
            ? self::formatRp($claimDisplayTotal)
            : null;

        $groups = $items
            ->groupBy(static fn (InvoiceItem $item): string => trim((string) ($item->barang?->no_index ?? '')) ?: '-')
            ->sortKeys();

        $indexCount = max(1, $groups->count());
        $isMulti = $indexCount > 1;

        // Satu frame: semua no. index dalam 1 tabel (Index+Item merge per index, CTN berlanjut).
        $tableRows = self::buildTableRows($items);
        $shippingFooter = self::buildShippingFooterFromItems($items);
        // Invoice yang sudah ada pembayaran approved: kunci ke snapshot invoice_items.extra.
        $extraForTotal = self::invoiceHasApprovedPayment($invoice)
            ? (float) $invoice->extra_charges
            : (float) $barangs->sum(
                static fn (Barang $barang): float => max(0, (float) ($barang->extra_charge ?? 0)),
            );
        [$beforeDiscount, $grandWithAdditional] = self::totalsFromShippingFooter(
            $shippingFooter,
            (float) $invoice->insurance_total,
            $extraForTotal,
            (float) $invoice->promo_discount,
        );
        $finalGrand = max(0, $grandWithAdditional);

        $section = self::buildViewPayload(
            $barangs,
            $invoice,
            $tableRows,
            $beforeDiscount,
            $finalGrand,
            $promoNote,
            self::resolvePrimaryMarking($barangs),
            null,
            $claimNote,
            $claimDisplayTotal,
            $shippingFooter,
        );
        $section['show_payment_footer'] = ! $isMulti;
        $section['show_letterhead'] = true;
        $section['noIndexLabel'] = self::joinUnique($barangs->map(
            static fn (Barang $barang): string => trim((string) ($barang->no_index ?? '')),
        ));
        $section['noPoLabel'] = self::joinUnique($barangs->map(
            static fn (Barang $barang): string => trim((string) ($barang->no_po ?? '')),
        ));
        $section['supplierLabel'] = self::joinUnique($barangs->map(
            static fn (Barang $barang): string => trim((string) ($barang->supplier_chinese ?? '')),
        )) ?: '-';

        $payload = array_merge($section, [
            'sections' => [$section],
            'is_multi_index' => $isMulti,
            'shipment_count' => $indexCount,
            'consolidated_summary' => $isMulti
                ? __('finance.consolidated_summary', ['count' => $indexCount])
                : null,
            'show_grand_footer' => $isMulti,
        ]);

        if ($isMulti) {
            $invoicePayment = InvoicePaymentSettings::current();
            $summary = self::buildTableSummary($barangs);
            $payload['grand_summary'] = [
                'coly' => $summary['coly'],
                'volume' => $summary['volume'],
                'weight' => $summary['weight'],
                'perhitungan' => self::formatRp($beforeDiscount),
                'claim_total' => self::claimTotalLabel($claimDisplayTotal),
                'total' => self::formatRp($finalGrand),
            ];
            $payload['invoicePaymentBank'] = $invoicePayment['bank'] !== '' ? $invoicePayment['bank'] : '-';
            $payload['invoicePaymentAccountName'] = $invoicePayment['account_name'] !== '' ? $invoicePayment['account_name'] : '-';
            $payload['invoicePaymentAccountNumber'] = $invoicePayment['account_number'] !== '' ? $invoicePayment['account_number'] : '-';
        }

        return $payload;
    }

    /**
     * Ocean Link invoice layout from barang rows (container / marking export before finance invoice).
     *
     * @param  Collection<int, Barang>  $barangs
     * @param  array{partai?: string|null, document_no?: string|null, invoice_date?: Carbon|null}  $context
     * @return array<string, mixed>
     */
    public static function forBarangs(Collection $barangs, array $context = []): array
    {
        $barangs = $barangs->values();
        self::loadBarangRelations($barangs);

        $marking = self::resolvePrimaryMarking($barangs);
        $insuranceTotal = 0.0;
        $extraTotal = 0.0;

        foreach ($barangs as $barang) {
            $insuranceTotal += (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
            $extraTotal += (float) ($barang->extra_charge ?? 0);
        }

        $tableRows = self::buildTableRowsFromBarangs($barangs);
        $shippingFooter = self::buildShippingFooterFromBarangs($barangs);
        [$beforeDiscount, $grandTotal] = self::totalsFromShippingFooter(
            $shippingFooter,
            $insuranceTotal,
            $extraTotal,
            0.0,
        );
        $invoiceDate = $context['invoice_date'] ?? now();
        $partaiOverride = isset($context['partai']) ? trim((string) $context['partai']) : '';

        $invoiceStub = new Invoice([
            'invoice_no' => $context['document_no'] ?? self::buildProformaDocumentNo($barangs, $partaiOverride),
            'created_at' => $invoiceDate,
        ]);
        $invoiceStub->setRelation('customer', $barangs->first()?->customer);

        return self::buildViewPayload(
            $barangs,
            $invoiceStub,
            $tableRows,
            $beforeDiscount,
            $grandTotal,
            null,
            $marking,
            $partaiOverride !== '' ? $partaiOverride : null,
            null,
            0.0,
            $shippingFooter,
        );
    }

    /**
     * Per-barang charge before promo/claims — same rules as invoice PDF row amounts + insurance + extra.
     */
    public static function barangChargeBeforeDiscountIdr(Barang $barang, ?InvoiceItem $item = null): float
    {
        self::loadBarangRelations(collect([$barang]));

        $insurance = $item !== null
            ? (float) $item->insurance
            : (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
        $extra = $item !== null
            ? (float) $item->extra
            : (float) ($barang->extra_charge ?? 0);

        $shippingFooter = self::buildShippingFooterFromBarangs(collect([$barang]), $item);

        [$beforeDiscount] = self::totalsFromShippingFooter($shippingFooter, $insurance, $extra, 0.0);

        return $beforeDiscount;
    }

    /**
     * Container invoice PDF grouped per marking (bongkar), one sheet per marking with index label.
     *
     * @return array{sections: list<array<string, mixed>>, partai: string}
     */
    public static function forContainerGrouped(Container $container): array
    {
        $container->load([
            'barangs' => static fn ($q) => $q->orderBy('no_index'),
            'port',
        ]);

        if ($container->barangs->isEmpty()) {
            return ['sections' => [], 'partai' => (string) ($container->partai ?? '')];
        }

        $groups = SuratJalanPdfData::buildMarkingGroups($container->barangs);
        $total = count($groups);
        $sections = [];

        foreach ($groups as $index => $group) {
            $markingCode = (string) ($group['code'] ?? '-');
            $section = self::forBarangs($group['barangs'], [
                'partai' => $container->partai,
                'document_no' => $container->partai.'-'.str($markingCode)->slug()->toString(),
            ]);
            $section['sheet_title'] = __('container.invoice_sheet_index_label', [
                'index' => $index + 1,
                'total' => $total,
                'marking' => $markingCode,
            ]);
            $section['marking_index'] = $index + 1;
            $section['marking_total'] = $total;
            $sections[] = $section;
        }

        return [
            'sections' => $sections,
            'partai' => (string) ($container->partai ?? ''),
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function loadBarangRelations(Collection $barangs): void
    {
        $persisted = $barangs->filter(static fn (Barang $b) => $b->exists)->values();
        if ($persisted->isEmpty()) {
            return;
        }

        (new EloquentCollection($persisted->all()))->loadMissing([
            'customer',
            'products' => static fn ($q) => $q->orderBy('sort_order'),
            'products.parent',
            'additionalPrices' => static fn ($q) => $q->orderBy('sort_order'),
            'containers:id,partai',
        ]);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return list<array<string, string>>
     */
    private static function buildTableRowsFromBarangs(Collection $barangs): array
    {
        $rows = [];
        $ctnCursor = 1;
        $groups = $barangs
            ->groupBy(static fn (Barang $barang): string => trim((string) ($barang->no_index ?? '')) ?: '-')
            ->sortKeys();

        $perIndexMinDisplay = ! self::usesInvoiceLevelMinOverweight($barangs);

        foreach ($groups as $indexNo => $groupBarangs) {
            $rows = array_merge(
                $rows,
                self::productRowsForIndexGroup(
                    $groupBarangs->values(),
                    (string) $indexNo,
                    $ctnCursor,
                    $perIndexMinDisplay,
                ),
            );
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    private static function buildViewPayload(
        Collection $barangs,
        Invoice $invoice,
        array $tableRows,
        float $beforeDiscount,
        float $grandTotal,
        ?string $promoNote,
        ?Marking $marking,
        ?string $partaiOverride = null,
        ?string $claimNote = null,
        float $claimTotalAmount = 0.0,
        ?array $shippingFooter = null,
    ): array {
        $invoicePayment = InvoicePaymentSettings::current();
        $partaiLabel = $partaiOverride !== null && $partaiOverride !== ''
            ? $partaiOverride
            : self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->container_code ?? ''))));

        $shippingFooter ??= self::buildShippingFooterFromBarangs($barangs);

        return [
            'invoice' => $invoice,
            'logoPath' => TerimaBarangPdfData::imageDataUri(self::resolveLogoPath()),
            'markingLabel' => self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->marking ?? '')))),
            'partaiLabel' => $partaiLabel,
            'tanggalMasukLabel' => self::formatTanggalMasukList($barangs),
            'customerName' => self::resolveCustomerName($invoice, $marking, $barangs),
            'customerAddress' => self::resolveCustomerAddress($invoice, $marking),
            'customerContact' => self::resolveCustomerContact($invoice, $marking),
            'paymentTerms' => self::resolvePaymentTerms($invoice, $barangs),
            'noIndexLabel' => self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->no_index ?? '')))),
            'invoiceDateLabel' => $invoice->created_at?->format('F j, Y') ?? '-',
            'tableRows' => $tableRows,
            'tableSummary' => self::buildTableSummary($barangs),
            'shippingFooter' => $shippingFooter,
            'perhitunganLabel' => self::formatRp($beforeDiscount),
            'totalLabel' => self::formatRp($grandTotal),
            'total_claim_amount' => $claimTotalAmount,
            'claimTotalLabel' => self::claimTotalLabel($claimTotalAmount),
            'claimRefundNote' => $claimTotalAmount > 0.009 ? __('finance.claim_refund_note') : null,
            'promoNote' => $promoNote,
            'claimNote' => $claimNote,
            'invoicePaymentBank' => $invoicePayment['bank'] !== '' ? $invoicePayment['bank'] : '-',
            'invoicePaymentAccountName' => $invoicePayment['account_name'] !== '' ? $invoicePayment['account_name'] : '-',
            'invoicePaymentAccountNumber' => $invoicePayment['account_number'] !== '' ? $invoicePayment['account_number'] : '-',
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, string>
     */
    private static function buildTableSummary(Collection $barangs): array
    {
        $totalColy = (int) $barangs->sum(static fn (Barang $barang): int => (int) ($barang->ctns ?? 0));
        $totalWeight = (float) $barangs->sum(static fn (Barang $barang): float => (float) ($barang->total_gross_weight ?? 0));
        $invoiceLevel = self::usesInvoiceLevelMinOverweight($barangs);

        $totalVolume = (float) $barangs->sum(static function (Barang $barang) use ($invoiceLevel): float {
            $actual = self::truncateInvoiceVolume(max(0, (float) ($barang->total_cbm ?? 0)));
            if ($invoiceLevel) {
                return $actual;
            }
            $minimum = BarangShippingPricing::resolveMinimumChargeCbm($barang);

            return self::billableVolumeForTotals($actual, $minimum);
        });
        $totalVolume = self::truncateInvoiceVolume($totalVolume);

        $volumeLabel = '0';
        if ($invoiceLevel) {
            $invoiceMinimum = 0.0;
            foreach ($barangs as $barang) {
                $invoiceMinimum = max($invoiceMinimum, BarangShippingPricing::resolveMinimumChargeCbm($barang));
            }
            $billableVolume = self::billableVolumeForTotals($totalVolume, $invoiceMinimum);
            if ($totalVolume > 0 || $billableVolume > 0) {
                $volumeLabel = $billableVolume > $totalVolume + 0.0005
                    ? self::formatVolumeWithMinCharge($totalVolume, $billableVolume)
                    : self::formatInvoiceVolume($totalVolume);
            }
        } elseif ($totalVolume > 0) {
            $volumeLabel = self::formatInvoiceVolume($totalVolume);
        }

        return [
            'coly' => $totalColy > 0 ? (string) $totalColy : '0',
            'volume' => $volumeLabel,
            'weight' => number_format($totalWeight, 2, '.', '').' kg',
            'length' => '-',
            'width' => '-',
            'height' => '-',
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function buildIndexSectionTitle(Collection $barangs, string $noIndex): string
    {
        $marking = self::joinUnique($barangs->map(
            static fn (Barang $barang): string => trim((string) ($barang->marking ?? '')),
        ));

        return trim($marking.' | No. Index: '.$noIndex);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function buildProformaDocumentNo(Collection $barangs, string $partaiOverride = ''): string
    {
        $partai = $partaiOverride !== ''
            ? $partaiOverride
            : self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->container_code ?? ''))));
        $marking = self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->marking ?? ''))));
        $slug = str($marking !== '-' ? $marking : 'tanpa-marking')->slug()->toString();

        if ($partai !== '-' && $partai !== '') {
            return $partai.'-'.$slug;
        }

        return self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->no_index ?? ''))));
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return list<array<string, string>>
     */
    private static function buildTableRows(Collection $items): array
    {
        $sortedItems = $items
            ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
            ->sortBy([
                static fn (InvoiceItem $item): string => mb_strtolower(trim((string) ($item->barang?->marking ?? ''))),
                static fn (InvoiceItem $item): string => mb_strtolower(trim((string) ($item->barang?->no_index ?? ''))),
            ])
            ->values();

        $rows = [];
        $ctnCursor = 1;
        $groups = $sortedItems
            ->groupBy(static fn (InvoiceItem $item): string => trim((string) ($item->barang?->no_index ?? '')) ?: '-')
            ->sortKeys();

        $allBarangs = $sortedItems
            ->map(static fn (InvoiceItem $item) => $item->barang)
            ->filter()
            ->values();
        $perIndexMinDisplay = ! self::usesInvoiceLevelMinOverweight($allBarangs);

        foreach ($groups as $indexNo => $groupItems) {
            $barangs = $groupItems
                ->map(static fn (InvoiceItem $item) => $item->barang)
                ->filter()
                ->values();
            $rows = array_merge(
                $rows,
                self::productRowsForIndexGroup($barangs, (string) $indexNo, $ctnCursor, $perIndexMinDisplay),
            );
        }

        return $rows;
    }

    /**
     * Satu no_index = satu blok merge Index+Item; CTN cursor dilanjutkan antar index
     * agar satu frame invoice tidak reset ke 1.
     *
     * @param  Collection<int, Barang>  $barangs
     * @return list<array<string, string|int|bool>>
     */
    private static function productRowsForIndexGroup(
        Collection $barangs,
        string $indexNo,
        int &$ctnCursor,
        bool $perIndexMinDisplay = true,
    ): array
    {
        $dimensionRows = [];
        $itemNames = [];
        $fallbackDescription = '-';

        foreach ($barangs as $barang) {
            [$rows, $names, $ctnCursor] = self::collectDimensionRowsForBarang(
                $barang,
                $ctnCursor,
                $perIndexMinDisplay,
            );
            $dimensionRows = array_merge($dimensionRows, $rows);
            $itemNames = array_merge($itemNames, $names);
            if ($fallbackDescription === '-') {
                $fallbackDescription = self::fallbackDescription($barang);
            }
        }

        if ($dimensionRows === []) {
            return [];
        }

        $uniqueNames = self::uniqueItemLabels($itemNames);
        $mergedItem = $uniqueNames !== [] ? implode(', ', $uniqueNames) : $fallbackDescription;
        $mergeCount = count($dimensionRows);
        $resolvedIndex = trim($indexNo) !== '' ? trim($indexNo) : '-';

        foreach ($dimensionRows as $i => $row) {
            $dimensionRows[$i]['index_no'] = $resolvedIndex;
            $dimensionRows[$i]['description'] = $mergedItem;
            $dimensionRows[$i]['row_kind'] = 'product';
            $dimensionRows[$i]['show_merge'] = $i === 0;
            $dimensionRows[$i]['merge_rowspan'] = $mergeCount;
            $dimensionRows[$i]['amount'] = '';
            $dimensionRows[$i]['base_price'] = '-';
        }

        return $dimensionRows;
    }

    /**
     * @return array{0: list<array<string, string|int|bool>>, 1: list<string>, 2: int}
     */
    private static function collectDimensionRowsForBarang(
        Barang $barang,
        int $ctnCursor,
        bool $perIndexMinDisplay = true,
    ): array
    {
        $products = $barang->products->sortBy('sort_order')->values();
        $parents = $products->whereNull('parent_id')->values();

        if ($parents->isEmpty() && $products->isNotEmpty()) {
            $parents = $products;
        }

        $dimensionRows = [];
        $itemNames = [];

        if ($parents->isEmpty()) {
            $dimensionRows[] = self::blankProductRow(
                self::fallbackDescription($barang),
                TerimaBarangPdfData::resolveNotesDisplay($barang),
                '',
                '-',
                $barang,
            );
            $label = trim(self::fallbackDescription($barang));
            if ($label !== '' && $label !== '-') {
                $itemNames[] = $label;
            }

            return [
                self::maybeApplyMinChargeVolumeDisplay($barang, $dimensionRows, $perIndexMinDisplay),
                $itemNames,
                $ctnCursor,
            ];
        }

        foreach ($parents as $product) {
            $name = trim((string) ($product->product_name ?? ''));
            $material = trim((string) ($product->material ?? ''));
            $label = $name !== '' ? $name : ($material !== '' ? $material : '');
            if ($label !== '') {
                $itemNames[] = $label;
            }

            $dimensionRows[] = self::productRowFromModel($product, $ctnCursor, '', false, '-', $barang);
            $ctns = max(1, (int) $product->ctns);
            $ctnCursor += $ctns;
            // Invoice: sub-item tidak ditampilkan — hanya header/parent product.
        }

        return [
            self::maybeApplyMinChargeVolumeDisplay($barang, $dimensionRows, $perIndexMinDisplay),
            $itemNames,
            $ctnCursor,
        ];
    }

    /**
     * Belum dimuat: tampilkan "0,027 -> 0,1" per index (min charge belum digabung di total invoice).
     *
     * @param  list<array<string, string|int|bool>>  $dimensionRows
     * @return list<array<string, string|int|bool>>
     */
    private static function maybeApplyMinChargeVolumeDisplay(
        Barang $barang,
        array $dimensionRows,
        bool $perIndexMinDisplay,
    ): array {
        if (! $perIndexMinDisplay || $dimensionRows === []) {
            return $dimensionRows;
        }

        $actual = self::truncateInvoiceVolume(max(0, (float) ($barang->total_cbm ?? 0)));
        $minimum = BarangShippingPricing::resolveMinimumChargeCbm($barang);
        $billable = self::billableVolumeForTotals($actual, $minimum);

        if ($billable <= $actual + 0.0005) {
            return $dimensionRows;
        }

        $label = self::formatVolumeWithMinCharge($actual, $billable);
        $targetIndex = null;
        foreach ($dimensionRows as $i => $row) {
            $volume = (string) ($row['volume'] ?? '-');
            if ($volume !== '-' && $volume !== '') {
                $targetIndex = $i;
                break;
            }
        }
        if ($targetIndex === null) {
            $targetIndex = 0;
        }

        $dimensionRows[$targetIndex]['volume'] = $label;

        return $dimensionRows;
    }

    /**
     * Format volume TOTAL saat min charge invoice: "0,027 -> 0,1".
     */
    public static function formatVolumeWithMinCharge(float $actualCbm, float $billableCbm): string
    {
        return CbmFormat::formatWithMinCharge($actualCbm, $billableCbm);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function uniqueItemLabels(array $names): array
    {
        $seen = [];
        $unique = [];

        foreach ($names as $name) {
            $label = trim($name);
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $label;
        }

        return $unique;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private static function withIndexNo(array $rows, string $indexNo): array
    {
        $resolved = trim($indexNo) !== '' ? trim($indexNo) : '-';

        return array_map(
            static function (array $row) use ($resolved): array {
                $row['index_no'] = $resolved;

                return $row;
            },
            $rows,
        );
    }

    /**
     * @return list<array<string, string|int|bool>>
     */
    private static function productRowsForBarang(Barang $barang, ?InvoiceItem $item = null): array
    {
        $ctnCursor = 1;
        $perIndexMinDisplay = ! self::usesInvoiceLevelMinOverweight(collect([$barang]));

        return self::productRowsForIndexGroup(
            collect([$barang]),
            (string) ($barang->no_index ?? '-'),
            $ctnCursor,
            $perIndexMinDisplay,
        );
    }

    /**
     * Footer wireframe: Keterangan overweight + Harga kubikasi / Harga berat / TOTAL.
     *
     * @param  Collection<int, InvoiceItem>  $items
     * @return array<string, mixed>
     */
    private static function buildShippingFooterFromItems(Collection $items): array
    {
        $pairs = [];
        foreach ($items as $item) {
            if ($item->barang === null) {
                continue;
            }
            $pairs[] = ['barang' => $item->barang, 'item' => $item];
        }

        return self::buildShippingFooterFromPairs($pairs);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    private static function buildShippingFooterFromBarangs(Collection $barangs, ?InvoiceItem $item = null): array
    {
        $pairs = [];
        foreach ($barangs as $barang) {
            $pairs[] = ['barang' => $barang, 'item' => $item];
        }

        return self::buildShippingFooterFromPairs($pairs);
    }

    /**
     * Footer ongkir invoice (satu / multi index):
     * Partai numerik sudah dimuat: min charge + overweight dari TOTAL CBM/kg.
     * Belum dimuat / tanpa partai numerik: min charge + overweight per index.
     *
     * @param  list<array{barang: Barang, item: ?InvoiceItem}>  $pairs
     * @return array<string, mixed>
     */
    private static function buildShippingFooterFromPairs(array $pairs): array
    {
        $empty = [
            'keterangan' => '(CBM)',
            'kubikasi_rows' => [],
            'kubikasi_rate' => '-',
            'kubikasi_amount' => '-',
            'berat_rows' => [],
            'berat_rate' => '-',
            'berat_amount' => '-',
            'shipping_total' => self::formatRp(0),
            'shipping_raw' => 0.0,
            'additional_rows' => [],
            'additional_raw' => 0.0,
            'charge_raw' => 0.0,
        ];

        if ($pairs === []) {
            return $empty;
        }

        $barangs = collect();
        foreach ($pairs as $pair) {
            $barangs->push($pair['barang']);
        }
        if (! self::usesInvoiceLevelMinOverweight($barangs)) {
            return self::buildShippingFooterPerIndexFromPairs($pairs, $empty);
        }

        $totalActualCbm = 0.0;
        $totalGw = 0.0;
        $invoiceMinCharge = 0.0;
        $additionalRows = [];
        $additionalRaw = 0.0;

        /** @var array<string, array{actual_cbm: float, gw: float, barang: Barang}> $indexGroups */
        $indexGroups = [];

        foreach ($pairs as $pair) {
            /** @var Barang $barang */
            $barang = $pair['barang'];
            $indexKey = trim((string) ($barang->no_index ?? '')) ?: '-';

            $actualCbm = self::truncateInvoiceVolume(max(0, (float) ($barang->total_cbm ?? 0)));
            $gw = max(0, (float) ($barang->total_gross_weight ?? 0));
            $invoiceMinCharge = max($invoiceMinCharge, BarangShippingPricing::resolveMinimumChargeCbm($barang));

            $totalActualCbm += $actualCbm;
            $totalGw += $gw;

            if (! isset($indexGroups[$indexKey])) {
                $indexGroups[$indexKey] = [
                    'actual_cbm' => 0.0,
                    'gw' => 0.0,
                    'barang' => $barang,
                ];
            }
            $indexGroups[$indexKey]['actual_cbm'] += $actualCbm;
            $indexGroups[$indexKey]['gw'] += $gw;

            foreach ($barang->additionalPrices ?? [] as $additional) {
                $name = trim((string) ($additional->name ?? ''));
                $price = (float) ($additional->price ?? 0);
                if ($name === '' && $price <= 0) {
                    continue;
                }
                $additionalRaw += max(0, $price);
                $additionalRows[] = [
                    'description' => $name !== '' ? $name : 'Additional',
                    'amount' => $price > 0 ? self::formatRp($price) : '-',
                ];
            }

            $item = $pair['item'] ?? null;
            $extraCharge = $item !== null
                ? max(0, (float) ($item->extra ?? 0))
                : max(0, (float) ($barang->extra_charge ?? 0));
            if ($extraCharge > 0.009) {
                $additionalRows[] = [
                    'description' => __('finance.col_extra'),
                    'amount' => self::formatRp($extraCharge),
                ];
            }
        }

        $totalActualCbm = self::truncateInvoiceVolume($totalActualCbm);
        $invoiceMinCharge = self::truncateInvoiceVolume($invoiceMinCharge);
        $totalBillableCbm = self::billableVolumeForTotals($totalActualCbm, $invoiceMinCharge);
        $extraCbm = self::truncateInvoiceVolume(max(0.0, $totalBillableCbm - $totalActualCbm));
        $extraTargetKey = self::indexKeyForInvoiceMinTopUp($indexGroups);

        $leadBarang = $indexGroups[$extraTargetKey]['barang'];
        $leadRates = BarangShippingPricing::resolveSellRates($leadBarang);
        $leadWeightPrice = (float) $leadRates['weight_price'];
        $normalizedThreshold = BarangShippingPricing::normalizeThresholdPerCbmKg(
            (float) $leadRates['weight_threshold_kg'],
        );

        $anyCbmPrice = false;
        $anyWeightPrice = false;
        foreach ($indexGroups as $group) {
            $rates = BarangShippingPricing::resolveSellRates($group['barang']);
            if ((float) $rates['price_per_cbm'] > 0) {
                $anyCbmPrice = true;
            }
            if ((float) $rates['weight_price'] > 0) {
                $anyWeightPrice = true;
            }
        }

        $capKg = $normalizedThreshold > 0 ? ($totalBillableCbm * $normalizedThreshold) : 0.0;
        $excessKg = ($capKg > 0 && $totalGw > $capKg && $anyWeightPrice) ? ($totalGw - $capKg) : 0.0;
        $invoiceIsWeightOnly = ! $anyCbmPrice && $anyWeightPrice && $totalGw > 0.009;
        $invoiceIsOver = ! $invoiceIsWeightOnly && $excessKg > 0.009 && $anyCbmPrice;

        $cbmAmount = 0.0;
        $weightAmount = 0.0;

        /** @var array<string, array{rate: float, indexes: list<string>, amount: float}> $cbmRateGroups */
        $cbmRateGroups = [];
        /** @var array<string, array{rate: float, indexes: list<string>, amount: float}> $weightRateGroups */
        $weightRateGroups = [];

        if ($invoiceIsWeightOnly) {
            $weightAmount = round($totalGw * $leadWeightPrice, 2);
            if ($leadWeightPrice > 0) {
                self::appendRateGroup($weightRateGroups, $leadWeightPrice, (string) $extraTargetKey, $weightAmount);
            }
        } else {
            foreach ($indexGroups as $indexKey => $group) {
                $rates = BarangShippingPricing::resolveSellRates($group['barang']);
                $groupPriceCbm = (float) $rates['price_per_cbm'];
                $volumeForCharge = self::truncateInvoiceVolume((float) $group['actual_cbm']);
                if ((string) $indexKey === (string) $extraTargetKey) {
                    $volumeForCharge = self::truncateInvoiceVolume($volumeForCharge + $extraCbm);
                }

                $groupCbmAmount = self::cbmAmountFromDisplayedVolume($volumeForCharge, $groupPriceCbm);
                $cbmAmount += $groupCbmAmount;
                if ($groupPriceCbm > 0) {
                    self::appendRateGroup($cbmRateGroups, $groupPriceCbm, (string) $indexKey, $groupCbmAmount);
                }
            }

            if ($invoiceIsOver) {
                $weightAmount = round($excessKg * $leadWeightPrice, 2);
                if ($leadWeightPrice > 0) {
                    self::appendRateGroup($weightRateGroups, $leadWeightPrice, (string) $extraTargetKey, $weightAmount);
                }
            }
        }

        $shippingRaw = round($cbmAmount + $weightAmount, 2);
        $keterangan = '(CBM)';
        if ($invoiceIsWeightOnly && $totalGw > 0.009) {
            $keterangan = 'CBM + over '.self::formatOverweightQty($totalGw).'kg';
        } elseif ($invoiceIsOver && $excessKg > 0.009) {
            $keterangan = 'CBM + over '.self::formatOverweightQty($excessKg).'kg';
        }

        $kubikasiRows = self::buildRateBreakdownRows($cbmRateGroups);
        $beratRows = self::buildRateBreakdownRows($weightRateGroups);

        return [
            'keterangan' => $keterangan,
            'kubikasi_rows' => $kubikasiRows,
            'kubikasi_rate' => self::rateRowsFallbackLabel($kubikasiRows),
            'kubikasi_amount' => $cbmAmount > 0.009 ? self::formatRp($cbmAmount) : '-',
            'berat_rows' => $beratRows,
            'berat_rate' => self::rateRowsFallbackLabel($beratRows),
            'berat_amount' => $weightAmount > 0.009 ? self::formatRp($weightAmount) : '-',
            'shipping_total' => self::formatRp($shippingRaw),
            'shipping_raw' => $shippingRaw,
            'additional_rows' => $additionalRows,
            'additional_raw' => round($additionalRaw, 2),
            'charge_raw' => round($shippingRaw + $additionalRaw, 2),
            'actual_cbm' => $totalActualCbm,
            'billable_cbm' => $totalBillableCbm,
        ];
    }

    /**
     * Belum dimuat / tanpa partai numerik: min charge + overweight per index, lalu dijumlah.
     *
     * @param  list<array{barang: Barang, item: ?InvoiceItem}>  $pairs
     * @param  array<string, mixed>  $empty
     * @return array<string, mixed>
     */
    private static function buildShippingFooterPerIndexFromPairs(array $pairs, array $empty): array
    {
        $totalActualCbm = 0.0;
        $totalBillableCbm = 0.0;
        $additionalRows = [];
        $additionalRaw = 0.0;

        /** @var array<string, array{billable_cbm: float, gw: float, barang: Barang}> $indexGroups */
        $indexGroups = [];

        foreach ($pairs as $pair) {
            /** @var Barang $barang */
            $barang = $pair['barang'];
            $indexKey = trim((string) ($barang->no_index ?? '')) ?: '-';

            $actualCbm = self::truncateInvoiceVolume(max(0, (float) ($barang->total_cbm ?? 0)));
            $minCharge = BarangShippingPricing::resolveMinimumChargeCbm($barang);
            $billableCbm = self::billableVolumeForTotals($actualCbm, $minCharge);
            $gw = max(0, (float) ($barang->total_gross_weight ?? 0));

            $totalActualCbm += $actualCbm;
            $totalBillableCbm += $billableCbm;

            if (! isset($indexGroups[$indexKey])) {
                $indexGroups[$indexKey] = [
                    'billable_cbm' => 0.0,
                    'gw' => 0.0,
                    'barang' => $barang,
                ];
            }
            $indexGroups[$indexKey]['billable_cbm'] += $billableCbm;
            $indexGroups[$indexKey]['gw'] += $gw;

            foreach ($barang->additionalPrices ?? [] as $additional) {
                $name = trim((string) ($additional->name ?? ''));
                $price = (float) ($additional->price ?? 0);
                if ($name === '' && $price <= 0) {
                    continue;
                }
                $additionalRaw += max(0, $price);
                $additionalRows[] = [
                    'description' => $name !== '' ? $name : 'Additional',
                    'amount' => $price > 0 ? self::formatRp($price) : '-',
                ];
            }

            $item = $pair['item'] ?? null;
            $extraCharge = $item !== null
                ? max(0, (float) ($item->extra ?? 0))
                : max(0, (float) ($barang->extra_charge ?? 0));
            if ($extraCharge > 0.009) {
                $additionalRows[] = [
                    'description' => __('finance.col_extra'),
                    'amount' => self::formatRp($extraCharge),
                ];
            }
        }

        $totalActualCbm = self::truncateInvoiceVolume($totalActualCbm);
        $totalBillableCbm = self::truncateInvoiceVolume($totalBillableCbm);

        $cbmAmount = 0.0;
        $weightAmount = 0.0;
        $totalExcessKg = 0.0;
        $hasOver = false;

        /** @var array<string, array{rate: float, indexes: list<string>, amount: float}> $cbmRateGroups */
        $cbmRateGroups = [];
        /** @var array<string, array{rate: float, indexes: list<string>, amount: float}> $weightRateGroups */
        $weightRateGroups = [];

        foreach ($indexGroups as $indexKey => $group) {
            /** @var Barang $groupBarang */
            $groupBarang = $group['barang'];
            $rates = BarangShippingPricing::resolveSellRates($groupBarang);
            $groupBillable = self::truncateInvoiceVolume((float) $group['billable_cbm']);
            $groupGw = (float) $group['gw'];
            $groupPriceCbm = (float) $rates['price_per_cbm'];
            $groupWeightPrice = (float) $rates['weight_price'];

            $pricing = BarangShippingPricing::preview(
                $groupBillable,
                $groupGw,
                (float) $rates['weight_threshold_kg'],
                $groupPriceCbm,
                $groupWeightPrice,
                0.0,
            );

            $volumeForCharge = self::truncateInvoiceVolume((float) ($pricing['billable_cbm'] ?? $groupBillable));
            $groupIsOver = in_array($pricing['method'], [
                BarangShippingPricing::METHOD_WEIGHT_SPLIT,
                BarangShippingPricing::METHOD_WEIGHT,
                BarangShippingPricing::METHOD_SPLIT,
            ], true);

            $groupCbmAmount = 0.0;
            $groupWeightAmount = 0.0;

            if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT) {
                $groupWeightAmount = max(0, (float) $pricing['weight_portion_idr']);
            } elseif ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT
                || $pricing['method'] === BarangShippingPricing::METHOD_SPLIT) {
                $groupCbmAmount = self::cbmAmountFromDisplayedVolume($volumeForCharge, $groupPriceCbm);
                $groupWeightAmount = max(0, (float) $pricing['weight_portion_idr']);
            } else {
                $groupCbmAmount = self::cbmAmountFromDisplayedVolume($volumeForCharge, $groupPriceCbm);
            }

            $cbmAmount += $groupCbmAmount;
            $weightAmount += $groupWeightAmount;

            if ($groupPriceCbm > 0) {
                self::appendRateGroup($cbmRateGroups, $groupPriceCbm, (string) $indexKey, $groupCbmAmount);
            }
            if ($groupWeightPrice > 0) {
                self::appendRateGroup($weightRateGroups, $groupWeightPrice, (string) $indexKey, $groupWeightAmount);
            }

            if ($groupIsOver) {
                $excessKg = max(0, (float) ($pricing['excess_kg'] ?? 0));
                if ($excessKg > 0.009) {
                    $hasOver = true;
                    $totalExcessKg += $excessKg;
                }
            }
        }

        $shippingRaw = round($cbmAmount + $weightAmount, 2);
        $keterangan = ($hasOver && $totalExcessKg > 0.009)
            ? 'CBM + over '.self::formatOverweightQty($totalExcessKg).'kg'
            : '(CBM)';

        $kubikasiRows = self::buildRateBreakdownRows($cbmRateGroups);
        $beratRows = self::buildRateBreakdownRows($weightRateGroups);

        return [
            'keterangan' => $keterangan,
            'kubikasi_rows' => $kubikasiRows,
            'kubikasi_rate' => self::rateRowsFallbackLabel($kubikasiRows),
            'kubikasi_amount' => $cbmAmount > 0.009 ? self::formatRp($cbmAmount) : '-',
            'berat_rows' => $beratRows,
            'berat_rate' => self::rateRowsFallbackLabel($beratRows),
            'berat_amount' => $weightAmount > 0.009 ? self::formatRp($weightAmount) : '-',
            'shipping_total' => self::formatRp($shippingRaw),
            'shipping_raw' => $shippingRaw,
            'additional_rows' => $additionalRows,
            'additional_raw' => round($additionalRaw, 2),
            'charge_raw' => round($shippingRaw + $additionalRaw, 2),
            'actual_cbm' => $totalActualCbm,
            'billable_cbm' => $totalBillableCbm,
        ];
    }

    /**
     * True jika semua partai numerik pada container invoice sudah dimuat (>= cutoff).
     * Belum dimuat / partai non-angka → rumus lama per index.
     *
     * @param  Collection<int, Barang>  $barangs
     */
    public static function usesInvoiceLevelMinOverweight(Collection $barangs): bool
    {
        $cutoff = self::INVOICE_LEVEL_MIN_OVERWEIGHT_FROM_PARTAI;
        $partaiNumbers = [];

        foreach ($barangs as $barang) {
            if (! $barang instanceof Barang) {
                continue;
            }

            $containers = $barang->relationLoaded('containers')
                ? $barang->containers
                : ($barang->exists ? $barang->containers()->get(['containers.id', 'partai']) : collect());

            foreach ($containers as $container) {
                $number = self::parsePartaiNumber((string) ($container->partai ?? ''));
                if ($number !== null) {
                    $partaiNumbers[] = $number;
                }
            }
        }

        if ($partaiNumbers === []) {
            return false;
        }

        foreach ($partaiNumbers as $number) {
            if ($number < $cutoff) {
                return false;
            }
        }

        return true;
    }

    public static function parsePartaiNumber(string $partai): ?int
    {
        $partai = trim($partai);
        if ($partai === '') {
            return null;
        }

        if (preg_match('/^(\d+)/', $partai, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Index yang menanggung sisa CBM min charge invoice (volume terbesar yang punya tarif CBM).
     *
     * @param  array<string, array{actual_cbm: float, gw: float, barang: Barang}>  $indexGroups
     */
    private static function indexKeyForInvoiceMinTopUp(array $indexGroups): string
    {
        $bestKey = (string) array_key_first($indexGroups);
        $bestCbm = -1.0;

        foreach ($indexGroups as $key => $group) {
            $rates = BarangShippingPricing::resolveSellRates($group['barang']);
            $cbm = (float) $group['actual_cbm'];
            if ((float) $rates['price_per_cbm'] <= 0) {
                continue;
            }
            if ($cbm > $bestCbm) {
                $bestCbm = $cbm;
                $bestKey = (string) $key;
            }
        }

        return $bestKey;
    }

    /**
     * @param  array<string, array{rate: float, indexes: list<string>, amount: float}>  $groups
     */
    private static function appendRateGroup(array &$groups, float $rate, string $indexKey, float $amount): void
    {
        $rounded = round(max(0, $rate), 2);
        if ($rounded <= 0) {
            return;
        }

        $key = (string) $rounded;
        if (! isset($groups[$key])) {
            $groups[$key] = [
                'rate' => $rounded,
                'indexes' => [],
                'amount' => 0.0,
            ];
        }

        if (! in_array($indexKey, $groups[$key]['indexes'], true)) {
            $groups[$key]['indexes'][] = $indexKey;
        }
        $groups[$key]['amount'] += max(0, $amount);
    }

    /**
     * Satu harga → satu baris "Rp X".
     * Beberapa harga → breakdown "Rp X (index1, index2)" per tarif (urut naik).
     *
     * @param  array<string, array{rate: float, indexes: list<string>, amount: float}>  $groups
     * @return list<array{label: string, amount: string, rate: float, indexes: list<string>}>
     */
    private static function buildRateBreakdownRows(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        uasort(
            $groups,
            static fn (array $a, array $b): int => $a['rate'] <=> $b['rate'],
        );

        $showIndexes = count($groups) > 1;
        $rows = [];
        foreach ($groups as $group) {
            $label = self::formatRp((float) $group['rate']);
            if ($showIndexes && $group['indexes'] !== []) {
                $label .= ' ('.implode(', ', $group['indexes']).')';
            }

            $rows[] = [
                'label' => $label,
                'amount' => ((float) $group['amount']) > 0.009
                    ? self::formatRp((float) $group['amount'])
                    : '-',
                'rate' => (float) $group['rate'],
                'indexes' => $group['indexes'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, amount: string, rate?: float, indexes?: list<string>}>  $rows
     */
    private static function rateRowsFallbackLabel(array $rows): string
    {
        if ($rows === []) {
            return '-';
        }

        return implode(' / ', array_map(
            static fn (array $row): string => (string) ($row['label'] ?? ''),
            $rows,
        ));
    }

    /**
     * Volume tagihan invoice: max(total aktual, min charge marking).
     */
    public static function billableVolumeForTotals(float $actualCbm, float $minimumChargeCbm): float
    {
        $actual = self::truncateInvoiceVolume(max(0, $actualCbm));
        $minimum = self::truncateInvoiceVolume(max(0, $minimumChargeCbm));

        if ($minimum <= 0) {
            return $actual;
        }

        return max($actual, $minimum);
    }

    /** @deprecated Use {@see billableVolumeForTotals()} — min charge dinilai dari total invoice. */
    public static function billableVolumeForIndex(float $actualCbm, float $minimumChargeCbm): float
    {
        return self::billableVolumeForTotals($actualCbm, $minimumChargeCbm);
    }

    /**
     * Ongkir agregat (sama aturan PDF) — dipakai Invoice::recalculate supaya grand_total selaras.
     *
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    public static function aggregateShippingFooter(Collection $barangs): array
    {
        return self::buildShippingFooterFromBarangs($barangs->values());
    }

    /**
     * @param  array<string, mixed>  $shippingFooter
     * @return array{0: float, 1: float}
     */
    private static function totalsFromShippingFooter(
        array $shippingFooter,
        float $insuranceTotal,
        float $extraCharges,
        float $promoDiscount,
    ): array {
        $beforeDiscount = (float) ($shippingFooter['charge_raw'] ?? 0) + $insuranceTotal + $extraCharges;
        $grandTotal = max(0, $beforeDiscount - $promoDiscount);

        return [$beforeDiscount, $grandTotal];
    }

    /**
     * Baris tagihan di bawah dimensi (Gambar 1): Harga Kubikasi / Harga Berat.
     * Over → CBM + overweight; tidak over → CBM saja.
     *
     * @param  array{
     *     method: string,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     *     excess_cbm: float,
     *     excess_kg: float,
     *     billable_cbm?: float,
     *     cap_cbm?: float,
     *     cap_kg?: float,
     * }  $pricing
     * @return list<array<string, string|int|bool>>
     */
    private static function shippingChargeRowsForBarang(Barang $barang, array $pricing, float $shippingIdr): array
    {
        if ($pricing['method'] === BarangShippingPricing::METHOD_SPLIT) {
            $capQty = BarangShippingPricing::normalizeThresholdPerCbmKg(
                (float) ($pricing['cap_cbm'] ?? BarangShippingPricing::resolveWeightThresholdKg($barang))
            );
            $excessQty = (float) $pricing['excess_cbm'];

            return [
                self::pricingChargeRow(
                    'Harga Kubikasi',
                    self::formatOverweightQty($capQty),
                    'CBM',
                    self::formatBasePrice((float) $barang->price_per_cbm, 'CBM'),
                    self::formatRp($pricing['cbm_portion_idr']),
                ),
                self::pricingChargeRow(
                    'Harga Berat',
                    self::formatOverweightQty($excessQty),
                    'KG',
                    self::formatBasePrice((float) $barang->weight_price, 'KG'),
                    self::formatRp($pricing['weight_portion_idr']),
                ),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT) {
            $excessQty = (float) $pricing['excess_kg'];
            $displayCbm = self::truncateInvoiceVolume((float) ($pricing['billable_cbm'] ?? $barang->total_cbm));
            $cbmPortionForDisplay = self::cbmAmountFromDisplayedVolume($displayCbm, (float) $barang->price_per_cbm);
            $capKg = (float) ($pricing['cap_kg'] ?? 0);
            if ($capKg <= 0) {
                $capKg = $displayCbm * BarangShippingPricing::normalizeThresholdPerCbmKg(
                    BarangShippingPricing::resolveWeightThresholdKg($barang),
                );
            }
            $actualCbm = self::truncateInvoiceVolume((float) $barang->displayTotalCbm());
            $volumeLabel = $displayCbm !== $actualCbm
                ? self::formatInvoiceVolume($displayCbm).' ('.self::formatInvoiceVolume($actualCbm).')'
                : self::formatInvoiceVolume($actualCbm);

            return [
                self::pricingChargeRow(
                    'Harga Kubikasi',
                    $volumeLabel,
                    'CBM',
                    self::formatBasePrice((float) $barang->price_per_cbm, 'CBM'),
                    self::formatRp($cbmPortionForDisplay),
                    self::formatOverweightQty($capKg),
                    '-',
                ),
                self::pricingChargeRow(
                    'Harga Berat',
                    '-',
                    'KG',
                    self::formatBasePrice((float) $barang->weight_price, 'KG'),
                    self::formatRp($pricing['weight_portion_idr']),
                    self::formatOverweightQty($excessQty),
                    '-',
                ),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT) {
            return [
                self::pricingChargeRow(
                    'Harga Berat',
                    '-',
                    'KG',
                    self::formatBasePrice((float) $barang->weight_price, 'KG'),
                    self::formatRp($pricing['weight_portion_idr'] > 0 ? $pricing['weight_portion_idr'] : $shippingIdr),
                    self::formatOverweightQty((float) $barang->total_gross_weight),
                    '-',
                ),
            ];
        }

        // CBM only (tidak over)
        $displayCbm = self::truncateInvoiceVolume((float) ($pricing['billable_cbm'] ?? $barang->displayTotalCbm()));
        $amount = $pricing['cbm_portion_idr'] > 0
            ? (float) $pricing['cbm_portion_idr']
            : self::cbmAmountFromDisplayedVolume($displayCbm, (float) $barang->price_per_cbm);

        return [
            self::pricingChargeRow(
                'Harga Kubikasi',
                self::formatInvoiceVolume($displayCbm),
                'CBM',
                self::formatBasePrice((float) $barang->price_per_cbm, 'CBM'),
                $amount > 0 ? self::formatRp($amount) : self::formatRp($shippingIdr),
            ),
        ];
    }

    /**
     * @deprecated kept for unit tests that target breakdown helpers
     *
     * @param  array{
     *     method: string,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     *     excess_cbm: float,
     *     excess_kg: float,
     * }  $pricing
     * @return list<array<string, string|int|bool>>
     */
    private static function shippingBreakdownRowsForBarang(Barang $barang, array $pricing): array
    {
        return self::shippingChargeRowsForBarang(
            $barang,
            $pricing,
            (float) ($pricing['subtotal_idr'] ?? BarangShippingPricing::shippingSubtotalIdr($barang)),
        );
    }

    /**
     * @return array<string, string|int|bool>
     */
    private static function pricingChargeRow(
        string $description,
        string $volume,
        string $notes,
        string $basePrice,
        string $amount,
        string $unitWeight = '-',
        string $totalWeight = '-',
    ): array {
        return [
            'description' => $description,
            'coly' => '-',
            'ctn_no' => '-',
            'length' => '-',
            'height' => '-',
            'width' => '-',
            'base_price' => $basePrice,
            'volume' => $volume,
            'unit_weight' => $unitWeight,
            'total_weight' => $totalWeight,
            'weight' => $totalWeight !== '-' ? $totalWeight : $unitWeight,
            'notes' => $notes,
            'amount' => $amount,
            'is_additional' => false,
            'row_kind' => 'pricing',
            'show_merge' => false,
            'merge_rowspan' => 1,
        ];
    }

    private static function formatOverweightQty(float $value): string
    {
        return rtrim(rtrim(number_format(max(0, $value), 2, '.', ''), '0'), '.');
    }

    private static function primaryLineDescription(Barang $barang): string
    {
        $product = $barang->products->sortBy('sort_order')->firstWhere('parent_id', null)
            ?? $barang->products->sortBy('sort_order')->first();

        if ($product !== null) {
            $name = trim((string) ($product->product_name ?? ''));

            return $name !== '' ? $name : self::fallbackDescription($barang);
        }

        return self::fallbackDescription($barang);
    }

    private static function formatWeightCell(Barang $barang): string
    {
        $gw = (float) $barang->total_gross_weight;

        return $gw > 0 ? number_format($gw, 2, '.', '').' kg' : '-';
    }

    /**
     * @return array<string, string>
     */
    private static function shippingBreakdownRow(
        array $baseRow,
        string $volume,
        string $notes,
        string $basePrice,
        string $amount,
    ): array {
        return [
            'description' => (string) ($baseRow['description'] ?? '-'),
            'coly' => (string) ($baseRow['coly'] ?? '-'),
            'ctn_no' => (string) ($baseRow['ctn_no'] ?? '-'),
            'length' => (string) ($baseRow['length'] ?? '-'),
            'height' => (string) ($baseRow['height'] ?? '-'),
            'width' => (string) ($baseRow['width'] ?? '-'),
            'base_price' => $basePrice,
            'volume' => $volume,
            'weight' => (string) ($baseRow['weight'] ?? '-'),
            'notes' => $notes,
            'amount' => $amount,
            'is_additional' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function primaryBreakdownBaseRow(Barang $barang): array
    {
        $product = $barang->products->sortBy('sort_order')->firstWhere('parent_id', null)
            ?? $barang->products->sortBy('sort_order')->first();

        if ($product instanceof BarangProduct) {
            return self::productRowFromModel($product, 1, '', false, '-', $barang);
        }

        return self::blankProductRow(
            self::fallbackDescription($barang),
            TerimaBarangPdfData::resolveNotesDisplay($barang),
            '',
            '-',
            $barang,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function secondaryBreakdownBaseRow(Barang $barang): array
    {
        return self::blankProductRow(
            self::primaryLineDescription($barang),
            '-',
            '',
            '-',
            $barang,
        );
    }

    private static function resolveShippingIdrForBarang(Barang $barang, ?InvoiceItem $item): float
    {
        if ($item !== null) {
            return max(
                0,
                (float) $item->line_total - (float) $item->insurance - (float) $item->extra,
            );
        }

        return BarangShippingPricing::shippingSubtotalIdr($barang);
    }

    /**
     * @param  array{
     *     method: string,
     *     subtotal_idr: float,
     *     billable_cbm: float,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     *     excess_cbm: float,
     *     excess_kg: float,
     * }  $pricing
     */
    private static function resolveShippingIdrForDisplay(Barang $barang, array $pricing, ?InvoiceItem $item): float
    {
        if ($pricing['method'] === BarangShippingPricing::METHOD_CBM) {
            $displayCbm = self::truncateInvoiceVolume((float) ($pricing['billable_cbm'] ?? $barang->total_cbm));

            return self::cbmAmountFromDisplayedVolume($displayCbm, (float) $barang->price_per_cbm);
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT) {
            $displayCbm = self::truncateInvoiceVolume((float) ($pricing['billable_cbm'] ?? $barang->total_cbm));
            $cbmPortion = self::cbmAmountFromDisplayedVolume($displayCbm, (float) $barang->price_per_cbm);

            return round($cbmPortion + max(0, (float) $pricing['weight_portion_idr']), 2);
        }

        return self::resolveShippingIdrForBarang($barang, $item);
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     * @return list<float>
     */
    private static function allocateShippingAmounts(Collection $products, float $shippingIdr): array
    {
        $count = $products->count();
        if ($count === 0 || $shippingIdr <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $volumes = $products->map(static fn (BarangProduct $p) => max(0, (float) $p->logisticsSource()->volume_cbm))->all();
        $totalVolume = array_sum($volumes);

        if ($totalVolume <= 0) {
            $perRow = round($shippingIdr / $count, 0);
            $amounts = array_fill(0, $count, $perRow);
            $amounts[$count - 1] = round($shippingIdr - ($perRow * ($count - 1)), 0);

            return $amounts;
        }

        $amounts = [];
        $remaining = $shippingIdr;

        foreach ($products->values() as $index => $product) {
            $isLast = $index === $count - 1;
            if ($isLast) {
                $amounts[] = round($remaining, 0);

                continue;
            }

            $share = round($shippingIdr * (max(0, (float) $product->volume_cbm) / $totalVolume), 0);
            $amounts[] = $share;
            $remaining -= $share;
        }

        return $amounts;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function additionalRowsForBarang(Barang $barang): array
    {
        $rows = [];

        foreach ($barang->additionalPrices as $additional) {
            $name = trim((string) ($additional->name ?? ''));
            $price = (float) ($additional->price ?? 0);
            if ($name === '' && $price <= 0) {
                continue;
            }

            $rows[] = [
                'description' => $name !== '' ? $name : 'Additional',
                'coly' => '-',
                'ctn_no' => '-',
                'length' => '-',
                'height' => '-',
                'width' => '-',
                'base_price' => '-',
                'volume' => '-',
                'unit_weight' => '-',
                'total_weight' => '-',
                'weight' => '-',
                'notes' => 'Additional',
                'amount' => $price > 0 ? self::formatRp($price) : '-',
                'is_additional' => true,
                'row_kind' => 'additional',
                'show_merge' => false,
                'merge_rowspan' => 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private static function productRowFromModel(
        BarangProduct $product,
        int $ctnCursor,
        string $amountLabel = '',
        bool $isSubLine = false,
        string $basePrice = '-',
        ?Barang $barang = null,
    ): array {
        $logistics = $product->logisticsSource();

        $name = trim((string) ($product->product_name ?? ''));
        $material = trim((string) ($product->material ?? ''));
        $description = $name !== '' ? $name : ($material !== '' ? $material : '-');

        $length = (float) $logistics->length_cm;
        $width = (float) $logistics->width_cm;
        $height = (float) $logistics->height_cm;

        [$gwLine, $gwTotal] = self::resolveProductWeights($logistics, $barang);
        $unitWeight = self::formatWeightPlain($gwLine);
        $totalWeight = self::formatWeightPlain($gwTotal);

        $notes = $material !== '' && $material !== $name ? $material : '-';

        if ($isSubLine) {
            $pcs = max(0, (int) $product->pcs_per_box);

            return [
                'description' => $description,
                'coly' => $pcs > 0 ? (string) $pcs : '-',
                'ctn_no' => '-',
                'length' => self::formatDimensionCm($length),
                'height' => self::formatDimensionCm($height),
                'width' => self::formatDimensionCm($width),
                'base_price' => $basePrice,
                'volume' => '-',
                'unit_weight' => '0',
                'total_weight' => '0',
                'weight' => '0',
                'notes' => $notes,
                'amount' => $amountLabel,
                'is_additional' => false,
                'row_kind' => 'product',
                'show_merge' => false,
                'merge_rowspan' => 1,
            ];
        }

        $ctns = max(1, (int) $product->ctns);
        $ctnEnd = $ctnCursor + $ctns - 1;
        $ctnNo = $ctns > 1 ? "{$ctnCursor}-{$ctnEnd}" : (string) $ctnCursor;
        // CBM: hitung dari P×L×T×CTNS lalu aturan 0–5 turun / 6–9 naik (bukan round dimensions).
        $lineCbm = CbmVolume::line($length, $width, $height, $ctns);

        return [
            'description' => $description,
            'coly' => (string) $ctns,
            'ctn_no' => $ctnNo,
            'length' => self::formatDimensionCm($length),
            'height' => self::formatDimensionCm($height),
            'width' => self::formatDimensionCm($width),
            'base_price' => $basePrice,
            'volume' => $lineCbm > 0 ? self::formatInvoiceVolume($lineCbm) : '-',
            'unit_weight' => $unitWeight,
            'total_weight' => $totalWeight,
            'weight' => $totalWeight,
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
            'row_kind' => 'product',
            'show_merge' => false,
            'merge_rowspan' => 1,
        ];
    }

    /**
     * @return array{0: float, 1: float} [unit kg per coly, line total kg]
     */
    private static function resolveProductWeights(BarangProduct $logistics, ?Barang $barang): array
    {
        $gwLine = max(0, (float) ($logistics->gross_weight_kg ?? 0));
        $gwTotal = max(0, (float) $logistics->lineGrossWeightKg());

        if ($gwTotal > 0.009 || $barang === null) {
            return [$gwLine, $gwTotal];
        }

        $barangGw = max(0, (float) ($barang->total_gross_weight ?? 0));
        if ($barangGw <= 0.009) {
            return [0.0, 0.0];
        }

        $parentProducts = $barang->relationLoaded('products')
            ? $barang->products->whereNull('parent_id')->values()
            : collect();

        // Satu parent (atau belum load relasi): pakai total GW barang.
        if ($parentProducts->count() <= 1) {
            $ctns = max(1, (int) ($logistics->ctns ?? 1));

            return [round($barangGw / $ctns, 2), $barangGw];
        }

        // Multi parent tanpa GW per produk: bagi proporsional menurut CTNS.
        $totalCtns = max(1, (int) $parentProducts->sum(
            static fn (BarangProduct $product): int => max(1, (int) $product->ctns),
        ));
        $ctns = max(1, (int) ($logistics->ctns ?? 1));
        $share = round($barangGw * ($ctns / $totalCtns), 2);

        return [round($share / $ctns, 2), $share];
    }

    /**
     * P/L/T: tampilkan nilai tersimpan tanpa dibulatkan (DB decimal 2).
     */
    private static function formatDimensionCm(float $value): string
    {
        if ($value <= 0) {
            return '-';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function formatWeightPlain(float $value): string
    {
        return rtrim(rtrim(number_format(max(0, $value), 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return array<string, string|int|bool>
     */
    private static function blankProductRow(
        string $description,
        string $notes,
        string $amountLabel = '',
        string $basePrice = '-',
        ?Barang $barang = null,
    ): array {
        $barangGw = $barang !== null ? max(0, (float) ($barang->total_gross_weight ?? 0)) : 0.0;
        $ctns = $barang !== null ? max(1, (int) ($barang->ctns ?? 1)) : 1;
        $unit = $barangGw > 0.009 ? round($barangGw / $ctns, 2) : 0.0;
        $unitWeight = self::formatWeightPlain($unit);
        $totalWeight = self::formatWeightPlain($barangGw);

        return [
            'description' => $description,
            'coly' => $barang !== null && (int) ($barang->ctns ?? 0) > 0 ? (string) (int) $barang->ctns : '-',
            'ctn_no' => '-',
            'length' => '-',
            'height' => '-',
            'width' => '-',
            'base_price' => $basePrice,
            'volume' => '-',
            'unit_weight' => $unitWeight,
            'total_weight' => $totalWeight,
            'weight' => $totalWeight,
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
            'row_kind' => 'product',
            'show_merge' => true,
            'merge_rowspan' => 1,
        ];
    }

    private static function basePriceForPricing(Barang $barang, string $method): string
    {
        if ($method === BarangShippingPricing::METHOD_WEIGHT) {
            return self::formatBasePrice((float) $barang->weight_price, 'KG');
        }

        return self::formatBasePrice((float) $barang->price_per_cbm, 'CBM');
    }

    private static function formatBasePrice(float $amount, string $unit): string
    {
        if ($amount <= 0) {
            return '-';
        }

        return self::formatRp($amount).' / '.$unit;
    }

    private static function formatInvoiceVolume(float $value): string
    {
        return CbmFormat::format($value, ',', '');
    }

    private static function truncateInvoiceVolume(float $value): float
    {
        return CbmFormat::truncate($value);
    }

    private static function cbmAmountFromDisplayedVolume(float $displayCbm, float $pricePerCbm): float
    {
        return round(max(0, $displayCbm) * max(0, $pricePerCbm), 2);
    }

    private static function fallbackDescription(Barang $barang): string
    {
        $items = TerimaBarangPdfData::formatItemsListPlain($barang->products, $barang);

        return $items !== '-' ? $items : (trim((string) ($barang->no_index ?? '')) ?: '-');
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolvePrimaryMarking(Collection $barangs): ?Marking
    {
        $value = $barangs
            ->map(static fn (Barang $b) => trim((string) ($b->marking ?? '')))
            ->first(static fn (string $m) => $m !== '');

        if ($value === null || $value === '') {
            return null;
        }

        return Marking::query()
            ->whereBarangMarkingValue($value)
            ->first();
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolveCustomerName(Invoice $invoice, ?Marking $marking, Collection $barangs): string
    {
        $fromUser = trim((string) ($invoice->customer?->name ?? ''));
        if ($fromUser !== '') {
            return $fromUser;
        }

        $fromMarking = trim((string) ($marking?->customer_name ?? ''));
        if ($fromMarking !== '') {
            return $fromMarking;
        }

        $fromBarang = trim((string) ($barangs->first()?->customer_name ?? ''));

        return $fromBarang !== '' ? $fromBarang : '-';
    }

    private static function resolveCustomerAddress(Invoice $invoice, ?Marking $marking): string
    {
        $userAddress = trim((string) ($invoice->customer?->address ?? ''));
        if ($userAddress !== '') {
            return $userAddress;
        }

        $parts = array_filter([
            trim((string) ($marking?->address_1 ?? '')),
            trim((string) ($marking?->address_2 ?? '')),
        ], static fn (string $p) => $p !== '');

        return $parts !== [] ? implode(', ', $parts) : '-';
    }

    private static function resolveCustomerContact(Invoice $invoice, ?Marking $marking): string
    {
        $phone = trim((string) ($invoice->customer?->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $markingPhone = trim((string) ($marking?->phone_hp ?? ''));
        if ($markingPhone !== '') {
            return $markingPhone;
        }

        $email = trim((string) ($marking?->email ?? ''));

        return $email !== '' ? $email : '-';
    }

    /**
     * Terms pembayaran on invoice PDF: Lunas kirim (due 0), Lunas (sudah bayar), atau Tempo.
     *
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolvePaymentTerms(Invoice $invoice, Collection $barangs): string
    {
        if (self::invoiceIsFullyPaid($invoice)) {
            return 'Lunas';
        }

        $dueDays = $barangs->isNotEmpty()
            ? InvoiceDueResolver::minDueDaysForBarangs($barangs)
            : null;

        if ($dueDays !== null && $dueDays <= 0) {
            return 'Lunas kirim';
        }

        $issuedOn = $invoice->created_at?->copy()->startOfDay();
        $dueDate = $invoice->due_date?->copy()->startOfDay();

        if ($issuedOn !== null && $dueDate !== null) {
            if ($dueDate->equalTo($issuedOn)) {
                return 'Lunas kirim';
            }

            return 'Tempo (jatuh tempo '.InvoiceStatusPresenter::formatDueDate($dueDate).')';
        }

        if ($dueDate !== null) {
            return 'Tempo (jatuh tempo '.InvoiceStatusPresenter::formatDueDate($dueDate).')';
        }

        $dueDays ??= InvoiceDueResolver::DEFAULT_DUE_DAYS;

        if ($dueDays <= 0) {
            return 'Lunas kirim';
        }

        $resolvedDue = $barangs->isNotEmpty()
            ? InvoiceDueResolver::resolveForBarangs($barangs)['due_date']
            : InvoiceDueResolver::dueDateFromDays($dueDays);

        return 'Tempo (jatuh tempo '.InvoiceStatusPresenter::formatDueDate($resolvedDue).')';
    }

    private static function invoiceIsFullyPaid(Invoice $invoice): bool
    {
        $grandTotal = (float) ($invoice->grand_total ?? 0);
        if ($grandTotal <= 0.009) {
            return false;
        }

        if ($invoice->relationLoaded('payments')) {
            $paid = (float) $invoice->payments
                ->where('status', 'approved')
                ->sum('amount');

            return InvoiceStatusPresenter::isFullyPaid($paid, max(0, $grandTotal - $paid));
        }

        if (! $invoice->exists) {
            return false;
        }

        $paid = (float) $invoice->payments()
            ->where('status', 'approved')
            ->sum('amount');

        return InvoiceStatusPresenter::isFullyPaid($paid, max(0, $grandTotal - $paid));
    }

    private static function invoiceHasApprovedPayment(Invoice $invoice): bool
    {
        if ($invoice->relationLoaded('payments')) {
            return (float) $invoice->payments->where('status', 'approved')->sum('amount') > 0.009;
        }

        if (! $invoice->exists) {
            return false;
        }

        return (float) $invoice->payments()->where('status', 'approved')->sum('amount') > 0.009;
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function formatTanggalMasukList(Collection $barangs): string
    {
        $dates = $barangs
            ->map(static fn (Barang $b) => $b->tanggal_masuk?->format('j-M-Y'))
            ->filter()
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return '-';
        }

        return self::joinUnique($dates);
    }

    /**
     * @param  Collection<int, string|null>  $values
     */
    private static function joinUnique(Collection $values): string
    {
        $unique = $values
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->unique()
            ->values();

        return $unique->isNotEmpty() ? $unique->implode(', ') : '-';
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function sumAdditionalPrices(Collection $barangs): float
    {
        return (float) $barangs->sum(
            static fn (Barang $barang) => $barang->additionalPrices->sum(
                static fn ($row) => (float) ($row->price ?? 0),
            ),
        );
    }

    private static function resolveClaimDisplayTotal(Invoice $invoice): float
    {
        if ($invoice->relationLoaded('claims')) {
            return (float) $invoice->claims
                ->whereIn('status', [Claim::STATUS_APPROVED, Claim::STATUS_PENDING])
                ->sum('amount');
        }

        if ($invoice->exists) {
            return (float) $invoice->claims()
                ->whereIn('status', [Claim::STATUS_APPROVED, Claim::STATUS_PENDING])
                ->sum('amount');
        }

        return (float) ($invoice->claim_deduction_total ?? 0);
    }

    private static function claimTotalLabel(float $amount): string
    {
        return $amount > 0.009 ? self::formatRp($amount) : '-';
    }

    public static function formatRp(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /**
     * Structured per-pengiriman blocks for finance UI / JSON APIs.
     *
     * @return array{
     *     is_multi_shipment: bool,
     *     shipment_count: int,
     *     consolidated_summary: string|null,
     *     sections: list<array<string, mixed>>
     * }
     */
    public static function apiShipmentPayload(Invoice $invoice): array
    {
        $view = self::forInvoice($invoice);
        $invoice->loadMissing([
            'items.barang' => static fn ($q) => $q->with([
                'products' => static fn ($pq) => $pq->orderBy('sort_order'),
                'additionalPrices' => static fn ($aq) => $aq->orderBy('sort_order'),
            ]),
        ]);

        $items = $invoice->items
            ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
            ->values();
        self::loadBarangRelations(
            $items->map(static fn (InvoiceItem $item) => $item->barang)->filter()->values()
        );

        $groups = $items
            ->groupBy(static fn (InvoiceItem $item): string => trim((string) ($item->barang?->no_index ?? '')) ?: '-')
            ->sortKeys();
        $count = max(1, $groups->count());
        $isMulti = $count > 1;

        $apiSections = [];
        $sectionIndex = 0;
        foreach ($groups as $noIndex => $groupItems) {
            $sectionIndex++;
            $groupItemsCollection = $groupItems->values();
            $groupBarangs = $groupItemsCollection
                ->map(static fn (InvoiceItem $item) => $item->barang)
                ->filter()
                ->values();
            $tableRows = self::buildTableRows($groupItemsCollection);
            $shippingFooter = self::buildShippingFooterFromItems($groupItemsCollection);
            [$beforeDiscount, $sectionGrand] = self::totalsFromShippingFooter($shippingFooter, 0.0, 0.0, 0.0);

            $apiSections[] = [
                'no_index' => (string) $noIndex,
                'no_po' => self::joinUnique($groupBarangs->map(
                    static fn (Barang $barang): string => trim((string) ($barang->no_po ?? '')),
                )),
                'marking' => self::joinUnique($groupBarangs->map(
                    static fn (Barang $barang): string => trim((string) ($barang->marking ?? '')),
                )),
                'supplier' => self::joinUnique($groupBarangs->map(
                    static fn (Barang $barang): string => trim((string) ($barang->supplier_chinese ?? '')),
                )) ?: '-',
                'section_index' => $sectionIndex,
                'section_total' => $count,
                'title' => self::buildIndexSectionTitle($groupBarangs, (string) $noIndex),
                'rows' => $tableRows,
                'summary' => self::buildTableSummary($groupBarangs),
                'subtotal_label' => self::formatRp($beforeDiscount),
                'total_label' => self::formatRp($sectionGrand),
            ];
        }

        $consolidatedSummary = $view['consolidated_summary'] ?? null;
        if ($isMulti && $consolidatedSummary === null) {
            $consolidatedSummary = __('finance.consolidated_summary', ['count' => $count]);
        }

        return [
            'is_multi_shipment' => $isMulti,
            'shipment_count' => (int) ($view['shipment_count'] ?? $count),
            'consolidated_summary' => is_string($consolidatedSummary) ? $consolidatedSummary : null,
            'sections' => $apiSections,
        ];
    }

    /**
     * @param  list<array<string, string>>  $tableRows
     * @return array{0: float, 1: float}
     */
    private static function totalsFromTableRows(
        array $tableRows,
        float $insuranceTotal,
        float $extraCharges,
        float $promoDiscount,
    ): array {
        $beforeDiscount = self::sumAmountsFromTableRows($tableRows) + $insuranceTotal + $extraCharges;
        $grandTotal = max(0, $beforeDiscount - $promoDiscount);

        return [$beforeDiscount, $grandTotal];
    }

    /**
     * @param  list<array<string, string>>  $tableRows
     */
    private static function sumAmountsFromTableRows(array $tableRows): float
    {
        $sum = 0.0;

        foreach ($tableRows as $row) {
            $sum += self::parseRpLabel((string) ($row['amount'] ?? ''));
        }

        return $sum;
    }

    private static function parseRpLabel(string $label): float
    {
        $label = trim($label);
        if ($label === '' || $label === '-') {
            return 0.0;
        }

        if (! str_starts_with($label, 'Rp ')) {
            return 0.0;
        }

        $digits = str_replace('.', '', substr($label, 3));

        return is_numeric($digits) ? (float) $digits : 0.0;
    }

    /**
     * Blank invoice layout matching exports.invoice-pdf with empty data fields.
     *
     * @return array<string, mixed>
     */
    public static function blankTemplate(): array
    {
        $invoicePayment = InvoicePaymentSettings::current();

        $section = [
            'markingLabel' => '',
            'partaiLabel' => '',
            'tanggalMasukLabel' => '',
            'customerName' => '',
            'customerAddress' => '',
            'customerContact' => '',
            'paymentTerms' => '',
            'noIndexLabel' => '',
            'invoiceDateLabel' => '',
            'tableRows' => [],
            'tableSummary' => [
                'coly' => '-',
                'length' => '-',
                'width' => '-',
                'height' => '-',
                'volume' => '-',
                'weight' => '-',
            ],
            'shippingFooter' => [
                'keterangan' => '(CBM)',
                'kubikasi_rows' => [],
                'kubikasi_rate' => '-',
                'kubikasi_amount' => '-',
                'berat_rows' => [],
                'berat_rate' => '-',
                'berat_amount' => '-',
                'shipping_total' => '-',
                'shipping_raw' => 0.0,
                'additional_rows' => [],
                'additional_raw' => 0.0,
                'charge_raw' => 0.0,
            ],
            'perhitunganLabel' => '',
            'totalLabel' => '',
            'promoNote' => null,
            'claimNote' => null,
            'claimRefundNote' => null,
            'invoicePaymentBank' => $invoicePayment['bank'] !== '' ? $invoicePayment['bank'] : '-',
            'invoicePaymentAccountName' => $invoicePayment['account_name'] !== '' ? $invoicePayment['account_name'] : '-',
            'invoicePaymentAccountNumber' => $invoicePayment['account_number'] !== '' ? $invoicePayment['account_number'] : '-',
            'show_payment_footer' => true,
            'logoPath' => self::resolveLogoPath(),
        ];

        return [
            'sections' => [$section],
            'is_multi_index' => false,
            'show_grand_footer' => false,
        ];
    }

    private static function resolveLogoPath(): ?string
    {
        $branding = Branding::absoluteLogoPath();
        if ($branding !== null && is_file($branding)) {
            return $branding;
        }

        $fallback = public_path('images/terima-ref-0.png');

        return is_file($fallback) ? $fallback : null;
    }
}
