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

        if ($groups->count() <= 1) {
            $tableRows = self::buildTableRows($items);
            [$beforeDiscount, $grandWithAdditional] = self::totalsFromTableRows(
                $tableRows,
                (float) $invoice->insurance_total,
                (float) $invoice->extra_charges,
                (float) $invoice->promo_discount,
            );

            $section = self::buildViewPayload(
                $barangs,
                $invoice,
                $tableRows,
                $beforeDiscount,
                max(0, $grandWithAdditional),
                $promoNote,
                self::resolvePrimaryMarking($barangs),
                null,
                $claimNote,
                $claimDisplayTotal,
            );
            $section['show_payment_footer'] = true;

            return array_merge($section, [
                'sections' => [$section],
                'is_multi_index' => false,
                'shipment_count' => 1,
                'consolidated_summary' => null,
                'show_grand_footer' => false,
            ]);
        }

        $sections = [];
        $sectionTotal = $groups->count();
        $sectionIndex = 0;
        $aggregateColy = 0;
        $aggregateVolume = 0.0;
        $aggregateWeight = 0.0;
        $aggregateShipping = 0.0;

        foreach ($groups as $noIndex => $groupItems) {
            $sectionIndex++;
            $groupBarangs = $groupItems
                ->map(static fn (InvoiceItem $item) => $item->barang)
                ->filter()
                ->values();
            $groupItemsCollection = $groupItems->values();
            $marking = self::resolvePrimaryMarking($groupBarangs);
            $tableRows = self::buildTableRows($groupItemsCollection);
            [$beforeDiscount, $sectionGrand] = self::totalsFromTableRows($tableRows, 0.0, 0.0, 0.0);

            $section = self::buildViewPayload(
                $groupBarangs,
                $invoice,
                $tableRows,
                $beforeDiscount,
                $sectionGrand,
                null,
                $marking,
                null,
                null,
            );
            $section['noIndexLabel'] = $noIndex;
            $section['noPoLabel'] = self::joinUnique($groupBarangs->map(
                static fn (Barang $barang): string => trim((string) ($barang->no_po ?? '')),
            ));
            $section['supplierLabel'] = self::joinUnique($groupBarangs->map(
                static fn (Barang $barang): string => trim((string) ($barang->supplier_chinese ?? '')),
            )) ?: '-';
            $section['show_payment_footer'] = false;
            $section['sheet_title'] = self::buildIndexSectionTitle($groupBarangs, (string) $noIndex);
            $section['index_section'] = $sectionIndex;
            $section['index_section_total'] = $sectionTotal;
            $section['show_letterhead'] = $sectionIndex === 1;
            $sections[] = $section;

            $summary = self::buildTableSummary($groupBarangs);
            $aggregateColy += (int) ($summary['coly'] !== '-' ? $summary['coly'] : 0);
            $aggregateVolume += (float) $groupBarangs->sum(static fn (Barang $b): float => (float) ($b->total_cbm ?? 0));
            $aggregateWeight += (float) $groupBarangs->sum(static fn (Barang $b): float => (float) ($b->total_gross_weight ?? 0));
            $aggregateShipping += $beforeDiscount;
        }

        $allRows = self::buildTableRows($items);
        [$beforeDiscountAll, $grandWithAdditional] = self::totalsFromTableRows(
            $allRows,
            (float) $invoice->insurance_total,
            (float) $invoice->extra_charges,
            (float) $invoice->promo_discount,
        );
        $finalGrand = max(0, $grandWithAdditional);
        $invoicePayment = InvoicePaymentSettings::current();

        $mergedRows = [];
        foreach ($sections as $section) {
            $mergedRows = array_merge($mergedRows, $section['tableRows'] ?? []);
        }

        return [
            'sections' => $sections,
            'is_multi_index' => true,
            'shipment_count' => $sectionTotal,
            'consolidated_summary' => __('finance.consolidated_summary', ['count' => $sectionTotal]),
            'show_grand_footer' => true,
            'invoice' => $invoice,
            'tableRows' => $mergedRows,
            'perhitunganLabel' => self::formatRp($beforeDiscountAll),
            'totalLabel' => self::formatRp($finalGrand),
            'markingLabel' => self::joinUnique($barangs->map(
                static fn (Barang $barang): string => trim((string) ($barang->marking ?? '')),
            )),
            'noIndexLabel' => self::joinUnique($barangs->map(
                static fn (Barang $barang): string => trim((string) ($barang->no_index ?? '')),
            )),
            'grand_summary' => [
                'coly' => $aggregateColy > 0 ? (string) $aggregateColy : '-',
                'volume' => $aggregateVolume > 0 ? self::formatInvoiceVolume($aggregateVolume) : '-',
                'weight' => $aggregateWeight > 0 ? number_format($aggregateWeight, 2, '.', '').' kg' : '-',
                'perhitungan' => self::formatRp($beforeDiscountAll),
                'claim_total' => self::claimTotalLabel($claimDisplayTotal),
                'total' => self::formatRp($finalGrand),
            ],
            'total_claim_amount' => $claimDisplayTotal,
            'claimTotalLabel' => self::claimTotalLabel($claimDisplayTotal),
            'claimRefundNote' => $claimDisplayTotal > 0.009 ? __('finance.claim_refund_note') : null,
            'promoNote' => $promoNote,
            'claimNote' => $claimNote,
            'invoicePaymentBank' => $invoicePayment['bank'] !== '' ? $invoicePayment['bank'] : '-',
            'invoicePaymentAccountName' => $invoicePayment['account_name'] !== '' ? $invoicePayment['account_name'] : '-',
            'invoicePaymentAccountNumber' => $invoicePayment['account_number'] !== '' ? $invoicePayment['account_number'] : '-',
        ];
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
        [$beforeDiscount, $grandTotal] = self::totalsFromTableRows(
            $tableRows,
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

        $tableRows = array_merge(
            self::productRowsForBarang($barang, $item),
            self::additionalRowsForBarang($barang),
        );

        [$beforeDiscount] = self::totalsFromTableRows($tableRows, $insurance, $extra, 0.0);

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

        foreach ($barangs as $barang) {
            $indexNo = (string) ($barang->no_index ?? '-');
            $rows = array_merge($rows, self::withIndexNo(self::productRowsForBarang($barang), $indexNo));
            $rows = array_merge($rows, self::withIndexNo(self::additionalRowsForBarang($barang), $indexNo));
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
    ): array {
        $invoicePayment = InvoicePaymentSettings::current();
        $partaiLabel = $partaiOverride !== null && $partaiOverride !== ''
            ? $partaiOverride
            : self::joinUnique($barangs->map(static fn (Barang $b) => trim((string) ($b->container_code ?? ''))));

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
        $totalVolume = (float) $barangs->sum(static fn (Barang $barang): float => (float) ($barang->total_cbm ?? 0));
        $totalWeight = (float) $barangs->sum(static fn (Barang $barang): float => (float) ($barang->total_gross_weight ?? 0));

        return [
            'coly' => $totalColy > 0 ? (string) $totalColy : '-',
            'volume' => $totalVolume > 0 ? self::formatInvoiceVolume($totalVolume) : '-',
            'weight' => $totalWeight > 0 ? number_format($totalWeight, 2, '.', '').' kg' : '-',
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
    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return list<array<string, string>>
     */
    private static function buildTableRows(Collection $items): array
    {
        $rows = [];
        $sortedItems = $items
            ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
            ->sortBy([
                static fn (InvoiceItem $item): string => mb_strtolower(trim((string) ($item->barang?->marking ?? ''))),
                static fn (InvoiceItem $item): string => mb_strtolower(trim((string) ($item->barang?->no_index ?? ''))),
            ])
            ->values();

        foreach ($sortedItems as $item) {
            $barang = $item->barang;
            if ($barang === null) {
                continue;
            }

            $rows = array_merge($rows, self::withIndexNo(
                self::productRowsForBarang($barang, $item),
                (string) ($barang->no_index ?? '-'),
            ));
            $rows = array_merge($rows, self::withIndexNo(
                self::additionalRowsForBarang($barang),
                (string) ($barang->no_index ?? '-'),
            ));
        }

        return $rows;
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
     * @return list<array<string, string>>
     */
    private static function productRowsForBarang(Barang $barang, ?InvoiceItem $item = null): array
    {
        $pricing = BarangShippingPricing::calculate($barang);

        if (in_array($pricing['method'], [
            BarangShippingPricing::METHOD_SPLIT,
            BarangShippingPricing::METHOD_WEIGHT_SPLIT,
        ], true)) {
            return self::shippingBreakdownRowsForBarang($barang, $pricing);
        }

        $shippingIdr = self::resolveShippingIdrForDisplay($barang, $pricing, $item);
        $allocateToProducts = $pricing['method'] === BarangShippingPricing::METHOD_CBM;
        $basePrice = self::basePriceForPricing($barang, $pricing['method']);
        $products = $barang->products->sortBy('sort_order')->values();
        $parents = $products->whereNull('parent_id')->values();

        if ($parents->isEmpty() && $products->isNotEmpty()) {
            $parents = $products;
        }

        if ($parents->isEmpty()) {
            $rows = [self::blankProductRow(
                self::fallbackDescription($barang),
                TerimaBarangPdfData::resolveNotesDisplay($barang),
                $allocateToProducts && $shippingIdr > 0 ? self::formatRp($shippingIdr) : '',
                $basePrice,
            )];

            return array_merge($rows, self::shippingBreakdownRowsForBarang($barang, $pricing));
        }

        $amounts = $allocateToProducts
            ? self::allocateShippingAmounts($parents, $shippingIdr)
            : array_fill(0, $parents->count(), 0.0);
        $rows = [];
        $ctnCursor = 1;

        foreach ($parents as $index => $product) {
            $amount = $amounts[$index] ?? 0.0;
            $rows[] = self::productRowFromModel(
                $product,
                $ctnCursor,
                $allocateToProducts && $amount > 0 ? self::formatRp($amount) : '',
                false,
                $basePrice,
            );
            $ctns = max(1, (int) $product->ctns);
            $ctnCursor += $ctns;

            foreach ($products->where('parent_id', $product->id) as $sub) {
                $rows[] = self::productRowFromModel($sub, $ctnCursor, '', true, '-');
            }
        }

        return array_merge($rows, self::shippingBreakdownRowsForBarang($barang, $pricing));
    }

    /**
     * Baris tagihan CBM + KG saat melebihi batas (selaras Terima Barang / form barang).
     *
     * @param  array{
     *     method: string,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     *     excess_cbm: float,
     *     excess_kg: float,
     * }  $pricing
     * @return list<array<string, string>>
     */
    private static function shippingBreakdownRowsForBarang(Barang $barang, array $pricing): array
    {
        $primaryRow = self::primaryBreakdownBaseRow($barang);
        $secondaryRow = self::secondaryBreakdownBaseRow($barang);

        if ($pricing['method'] === BarangShippingPricing::METHOD_SPLIT) {
            $capQty = BarangShippingPricing::normalizeThresholdPerCbmKg(
                (float) ($pricing['cap_cbm'] ?? $barang->weight_threshold_kg)
            );
            $excessQty = (float) $pricing['excess_cbm'];

            return [
                self::shippingBreakdownRow(
                    $primaryRow,
                    self::formatOverweightQty($capQty),
                    'CBM',
                    self::formatBasePrice((float) $barang->price_per_cbm, 'CBM'),
                    self::formatRp($pricing['cbm_portion_idr']),
                ),
                self::shippingBreakdownRow(
                    $secondaryRow,
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
                $capKg = $displayCbm * BarangShippingPricing::normalizeThresholdPerCbmKg((float) $barang->weight_threshold_kg);
            }
            $actualCbm = self::truncateInvoiceVolume((float) $barang->total_cbm);
            $primaryRow['volume'] = $displayCbm !== $actualCbm
                ? self::formatInvoiceVolume($displayCbm).' ('.self::formatInvoiceVolume($actualCbm).')'
                : self::formatInvoiceVolume($actualCbm);
            $primaryRow['weight'] = self::formatOverweightQty($capKg);
            $secondaryRow['weight'] = self::formatOverweightQty($excessQty);

            return [
                self::shippingBreakdownRow(
                    $primaryRow,
                    (string) ($primaryRow['volume'] ?? '-'),
                    'CBM',
                    self::formatBasePrice((float) $barang->price_per_cbm, 'CBM'),
                    self::formatRp($cbmPortionForDisplay),
                ),
                self::shippingBreakdownRow(
                    $secondaryRow,
                    (string) ($secondaryRow['volume'] ?? '-'),
                    'KG',
                    self::formatBasePrice((float) $barang->weight_price, 'KG'),
                    self::formatRp($pricing['weight_portion_idr']),
                ),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT) {
            return [
                self::shippingBreakdownRow(
                    $primaryRow,
                    self::formatOverweightQty((float) $barang->total_gross_weight),
                    'KG',
                    self::formatBasePrice((float) $barang->weight_price, 'KG'),
                    self::formatRp($pricing['weight_portion_idr']),
                ),
            ];
        }

        return [];
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
            return self::productRowFromModel($product, 1);
        }

        return self::blankProductRow(
            self::fallbackDescription($barang),
            TerimaBarangPdfData::resolveNotesDisplay($barang),
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
                'weight' => '-',
                'notes' => 'Additional',
                'amount' => $price > 0 ? self::formatRp($price) : '-',
                'is_additional' => true,
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
    ): array {
        $logistics = $product->logisticsSource();

        $name = trim((string) ($product->product_name ?? ''));
        $material = trim((string) ($product->material ?? ''));
        $description = $name !== '' ? $name : ($material !== '' ? $material : '-');

        $length = (float) $logistics->length_cm;
        $width = (float) $logistics->width_cm;
        $height = (float) $logistics->height_cm;

        $gwLine = (float) $logistics->gross_weight_kg;
        $gwTotal = $logistics->lineGrossWeightKg();
        $weight = $gwTotal > 0
            ? number_format($gwLine, 1, '.', '').' / '.number_format($gwTotal, 1, '.', '')
            : '-';

        $notes = $material !== '' && $material !== $name ? $material : '-';

        if ($isSubLine) {
            $pcs = max(0, (int) $product->pcs_per_box);

            return [
                'description' => $description,
                'coly' => $pcs > 0 ? (string) $pcs : '-',
                'ctn_no' => '-',
                'length' => $length > 0 ? number_format($length, 0, '.', '') : '-',
                'height' => $height > 0 ? number_format($height, 0, '.', '') : '-',
                'width' => $width > 0 ? number_format($width, 0, '.', '') : '-',
                'base_price' => $basePrice,
                'volume' => '-',
                'weight' => '-',
                'notes' => $notes,
                'amount' => $amountLabel,
                'is_additional' => false,
            ];
        }

        $ctns = max(1, (int) $product->ctns);
        $ctnEnd = $ctnCursor + $ctns - 1;
        $ctnNo = $ctns > 1 ? "{$ctnCursor}-{$ctnEnd}" : (string) $ctnCursor;
        $lineCbm = (float) $logistics->volume_cbm;

        return [
            'description' => $description,
            'coly' => (string) $ctns,
            'ctn_no' => $ctnNo,
            'length' => $length > 0 ? number_format($length, 0, '.', '') : '-',
            'height' => $height > 0 ? number_format($height, 0, '.', '') : '-',
            'width' => $width > 0 ? number_format($width, 0, '.', '') : '-',
            'base_price' => $basePrice,
            'volume' => $lineCbm > 0 ? self::formatInvoiceVolume($lineCbm) : '-',
            'weight' => $weight,
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function blankProductRow(
        string $description,
        string $notes,
        string $amountLabel = '',
        string $basePrice = '-',
    ): array
    {
        return [
            'description' => $description,
            'coly' => '-',
            'ctn_no' => '-',
            'length' => '-',
            'height' => '-',
            'width' => '-',
            'base_price' => $basePrice,
            'volume' => '-',
            'weight' => '-',
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
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
     * Terms pembayaran on invoice PDF: Lunas (cash / fully paid) or Tempo (credit with due date).
     *
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolvePaymentTerms(Invoice $invoice, Collection $barangs): string
    {
        if (self::invoiceIsFullyPaid($invoice)) {
            return 'Lunas';
        }

        $issuedOn = $invoice->created_at?->copy()->startOfDay();
        $dueDate = $invoice->due_date?->copy()->startOfDay();

        if ($issuedOn !== null && $dueDate !== null) {
            if ($dueDate->equalTo($issuedOn)) {
                return 'Lunas';
            }

            return 'Tempo (jatuh tempo '.InvoiceStatusPresenter::formatDueDate($dueDate).')';
        }

        if ($dueDate !== null) {
            return 'Tempo (jatuh tempo '.InvoiceStatusPresenter::formatDueDate($dueDate).')';
        }

        $dueDays = $barangs->isNotEmpty()
            ? InvoiceDueResolver::minDueDaysForBarangs($barangs)
            : InvoiceDueResolver::DEFAULT_DUE_DAYS;

        if ($dueDays <= 0) {
            return 'Lunas';
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
        $sections = $view['sections'] ?? [];
        $count = count($sections);
        $isMulti = (bool) ($view['is_multi_index'] ?? false);

        $apiSections = [];
        foreach ($sections as $index => $section) {
            $apiSections[] = [
                'no_index' => (string) ($section['noIndexLabel'] ?? '-'),
                'no_po' => (string) ($section['noPoLabel'] ?? '-'),
                'marking' => (string) ($section['markingLabel'] ?? '-'),
                'supplier' => (string) ($section['supplierLabel'] ?? '-'),
                'section_index' => (int) ($section['index_section'] ?? ($index + 1)),
                'section_total' => (int) ($section['index_section_total'] ?? $count),
                'title' => $section['sheet_title'] ?? null,
                'rows' => $section['tableRows'] ?? [],
                'summary' => $section['tableSummary'] ?? [],
                'subtotal_label' => (string) ($section['perhitunganLabel'] ?? '-'),
                'total_label' => (string) ($section['totalLabel'] ?? '-'),
            ];
        }

        $consolidatedSummary = $view['consolidated_summary'] ?? null;
        if ($isMulti && $consolidatedSummary === null && $count > 1) {
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
