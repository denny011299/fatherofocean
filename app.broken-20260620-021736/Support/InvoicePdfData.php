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

        $barangs = $invoice->items
            ->map(static fn (InvoiceItem $item) => $item->barang)
            ->filter()
            ->values();

        self::loadBarangRelations($barangs);

        $marking = self::resolvePrimaryMarking($barangs);
        $tableRows = self::buildTableRows($invoice->items);
        [$beforeDiscount, $grandWithAdditional] = self::totalsFromTableRows(
            $tableRows,
            (float) $invoice->insurance_total,
            (float) $invoice->extra_charges,
            (float) $invoice->promo_discount,
        );

        return self::buildViewPayload(
            $barangs,
            $invoice,
            $tableRows,
            $beforeDiscount,
            $grandWithAdditional,
            (float) $invoice->promo_discount > 0
                ? 'Promo '.$invoice->promo_name.' ('.number_format((float) $invoice->promo_persen, 2, ',', '.').'%): -'.self::formatRp((float) $invoice->promo_discount)
                : null,
            $marking,
        );
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
            'barangs' => static fn ($q) => $q->with('dimensions')->orderBy('no_index'),
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
            'dimensions',
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
    ): array {
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
            'perhitunganLabel' => self::formatRp($beforeDiscount),
            'totalLabel' => self::formatRp($grandTotal),
            'promoNote' => $promoNote,
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

        foreach ($items as $item) {
            $barang = $item->barang;
            if ($barang === null) {
                continue;
            }

            $rows = array_merge($rows, self::productRowsForBarang($barang, $item));
            $rows = array_merge($rows, self::additionalRowsForBarang($barang));
        }

        return $rows;
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

        $shippingIdr = self::resolveShippingIdrForBarang($barang, $item);
        $allocateToProducts = $pricing['method'] === BarangShippingPricing::METHOD_CBM;
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
            );
            $ctns = max(1, (int) $product->ctns);
            $ctnCursor += $ctns;

            foreach ($products->where('parent_id', $product->id) as $sub) {
                $rows[] = self::productRowFromModel($sub, $ctnCursor, '', true);
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
        $description = self::primaryLineDescription($barang);

        if ($pricing['method'] === BarangShippingPricing::METHOD_SPLIT) {
            $capQty = (float) ($pricing['cap_cbm'] ?? $barang->weight_threshold_kg);
            $excessQty = (float) $pricing['excess_cbm'];

            return [
                self::shippingBreakdownRow(
                    $description,
                    self::formatOverweightQty($capQty),
                    '-',
                    'CBM',
                    self::formatRp($pricing['cbm_portion_idr']),
                ),
                self::shippingBreakdownRow(
                    $description,
                    self::formatOverweightQty($excessQty),
                    '-',
                    'KG',
                    self::formatRp($pricing['weight_portion_idr']),
                ),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT) {
            $capQty = (float) $barang->weight_threshold_kg;
            $excessQty = (float) $pricing['excess_kg'];

            return [
                self::shippingBreakdownRow(
                    $description,
                    self::formatOverweightQty($capQty),
                    '-',
                    'CBM',
                    self::formatRp($pricing['cbm_portion_idr']),
                ),
                self::shippingBreakdownRow(
                    $description,
                    self::formatOverweightQty($excessQty),
                    '-',
                    'KG',
                    self::formatRp($pricing['weight_portion_idr']),
                ),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT) {
            return [
                self::shippingBreakdownRow(
                    $description,
                    self::formatOverweightQty((float) $barang->total_gross_weight),
                    '-',
                    'KG',
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
        string $description,
        string $volume,
        string $weight,
        string $notes,
        string $amount,
    ): array {
        return [
            'description' => $description,
            'coly' => '-',
            'ctn_no' => '-',
            'length' => '-',
            'height' => '-',
            'width' => '-',
            'volume' => $volume,
            'weight' => $weight,
            'notes' => $notes,
            'amount' => $amount,
            'is_additional' => false,
        ];
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
            'volume' => $lineCbm > 0 ? CbmFormat::format($lineCbm) : '-',
            'weight' => $weight,
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function blankProductRow(string $description, string $notes, string $amountLabel = ''): array
    {
        return [
            'description' => $description,
            'coly' => '-',
            'ctn_no' => '-',
            'length' => '-',
            'height' => '-',
            'width' => '-',
            'volume' => '-',
            'weight' => '-',
            'notes' => $notes,
            'amount' => $amountLabel,
            'is_additional' => false,
        ];
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
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolvePaymentTerms(Invoice $invoice, Collection $barangs): string
    {
        if ($invoice->due_date !== null) {
            $days = InvoiceDueResolver::minDueDaysForBarangs($barangs);
            $dueFormatted = $invoice->due_date->format('j F Y');

            if ($days <= 0) {
                return 'Jatuh tempo: '.$dueFormatted.' (segera)';
            }

            return $days.' hari — jatuh tempo '.$dueFormatted;
        }

        $days = InvoiceDueResolver::minDueDaysForBarangs($barangs);
        if ($days <= 0) {
            return 'Pembayaran segera setelah invoice terbit';
        }

        return $days.' hari setelah invoice terbit';
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
