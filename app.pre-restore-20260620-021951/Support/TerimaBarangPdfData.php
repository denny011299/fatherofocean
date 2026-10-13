<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangAdditionalPrice;
use App\Models\BarangProduct;
use App\Models\Marking;
use Illuminate\Support\Collection;

final class TerimaBarangPdfData
{
    /**
     * @return array<string, mixed>
     */
    public static function forBarang(Barang $barang): array
    {
        $barang->loadMissing([
            'products' => static fn ($q) => $q->orderBy('sort_order')->with('parent'),
            'additionalPrices' => static fn ($q) => $q->orderBy('sort_order'),
        ]);

        $marking = null;
        if ($barang->marking !== null && $barang->marking !== '') {
            $marking = Marking::visibleToUser()
                ->with('sales:id,name')
                ->whereBarangMarkingValue($barang->marking)
                ->first();
        }

        $trackingNumber = self::resolveTrackingNumber($barang);
        $productLabels = self::collectProductLabels($barang->products);
        $itemsPlain = self::formatItemsListPlain($barang->products, $barang);
        $packingGroups = self::buildPackingRowGroups($barang->products);
        $packingRows = self::flattenPackingGroups($packingGroups);
        if ($productLabels->isEmpty()) {
            $itemsPlain = self::formatFallbackItemsPlain($barang);
            $productLabels = collect(preg_split('/\s*,\s*/', $itemsPlain) ?: [])->filter()->values();
        }
        $shippingBaseIdr = self::estimatedShippingTotalIdr($barang);
        $storedPriceIdr = (float) ($barang->price ?? 0);
        $priceIdr = $storedPriceIdr > 0 ? $storedPriceIdr : $shippingBaseIdr;
        $additionalIdr = (float) $barang->additionalPrices->sum('price');
        $totalIdr = $priceIdr + $additionalIdr;
        $volumeValue = CbmFormat::format((float) $barang->total_cbm);
        $colyCount = self::resolveColyCount($barang);
        $dimensions = self::primaryProductDimensions($barang);

        return [
            'barang' => $barang,
            'marking' => $marking,
            'markingCode' => $barang->marking ?? '-',
            'marketingName' => $marking?->sales?->name ?? '-',
            'trackingNumber' => $trackingNumber !== '' ? $trackingNumber : '-',
            'supplierDisplay' => trim((string) ($barang->supplier_chinese ?? '')) !== '' ? $barang->supplier_chinese : '-',
            'entryDateLabel' => $barang->tanggal_masuk?->format('j-M-Y') ?? '-',
            'itemsPlain' => $itemsPlain,
            'itemsDisplay' => self::formatItemsListMultiline($barang->products, $barang),
            'receiptRows' => array_merge(
                self::buildReceiptSummaryRows($barang),
                self::buildReceiptAdditionalRows($barang),
            ),
            'volumeValue' => $volumeValue,
            'weightValue' => self::formatWeightColumn($barang, true),
            'notesDisplay' => self::resolveNotesDisplay($barang),
            'colyCount' => $colyCount,
            'priceLabel' => self::formatRp($priceIdr),
            'totalLabel' => self::formatRp($totalIdr),
            'logoPath' => self::imageDataUri(self::resolveLogoPath()),
            'goodReceiptPath' => TerimaBarangPdfImages::fitDataUri(
                self::absolutePublicPath($barang->good_receipt_path),
                TerimaBarangPdfImages::RECEIPT_W,
                TerimaBarangPdfImages::RECEIPT_H,
            ),
            'packingGroups' => $packingGroups,
            'packingRows' => $packingRows,
            'packingTotals' => self::summarizePackingRowGroups($packingGroups),
            'invoiceImagePath' => TerimaBarangPdfImages::fitDataUri(
                self::absolutePublicPath($barang->invoice_file),
                TerimaBarangPdfImages::INVOICE_MAX_W,
                TerimaBarangPdfImages::INVOICE_MAX_H,
                92,
            ),
            'summaryLength' => $dimensions['length'],
            'summaryWidth' => $dimensions['width'],
            'summaryHeight' => $dimensions['height'],
            'showPackingTable' => $packingGroups !== [],
            'showDimensionProofPage' => self::hasDimensionProofPhotos($packingGroups),
            'showInvoiceImagePage' => false,
            ...self::resolvePdfFontConfig(),
        ];
    }

    /**
     * @return array{
     *     pdfFontFamily: string,
     *     cjkFontPath: ?string,
     *     cjkFontUri: ?string,
     *     hasCjkFont: bool
     * }
     */
    public static function resolvePdfFontConfig(): array
    {
        $cjkPath = TerimaBarangPdfFonts::absolutePath();

        if ($cjkPath === null) {
            return [
                'pdfFontFamily' => 'DejaVu Sans',
                'cjkFontFamily' => null,
                'cjkFontPath' => null,
                'cjkFontUri' => null,
                'cjkFontFormat' => null,
                'hasCjkFont' => false,
            ];
        }

        TerimaBarangPdfFonts::preloadMetrics($cjkPath);
        $normalized = str_replace('\\', '/', $cjkPath);

        $dompdfPath = TerimaBarangPdfFonts::dompdfFontPath(storage_path('fonts')) ?? $normalized;

        return [
            'pdfFontFamily' => 'DejaVu Sans',
            'cjkFontFamily' => TerimaBarangPdfFonts::FAMILY,
            'cjkFontPath' => $normalized,
            'cjkFontRelative' => self::pathRelativeToBase($dompdfPath),
            'cjkFontUri' => self::fontFileUri($normalized),
            'cjkFontFormat' => str_ends_with(strtolower($normalized), '.otf') ? 'opentype' : 'truetype',
            'hasCjkFont' => true,
        ];
    }

    public static function imageDataUri(?string $absolutePath): ?string
    {
        if ($absolutePath === null || $absolutePath === '' || ! is_file($absolutePath)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        $contents = @file_get_contents($absolutePath);
        if ($contents === false || $contents === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public static function estimatedShippingTotalIdr(Barang $barang): float
    {
        return BarangShippingPricing::shippingSubtotalIdr($barang);
    }

    /**
     * English + Chinese in one font run (avoids Dompdf mixed-font tofu in bold labels).
     */
    public static function bilingualLabelHtml(
        bool $enabled,
        string $english,
        string $chinese,
        bool $uppercaseEnglish = true,
    ): string {
        $english = trim($english);
        $chinese = trim($chinese);
        $chinesePart = $chinese !== '' ? ' ('.$chinese.')' : '';
        $displayEnglish = $uppercaseEnglish ? mb_strtoupper($english, 'UTF-8') : $english;
        $full = $displayEnglish.$chinesePart;

        if (! $enabled) {
            return htmlspecialchars($full, ENT_QUOTES, 'UTF-8');
        }

        return self::cjkSpan($full, '');
    }

    public static function cjkHtml(bool $enabled, string $text, string $leading = ' '): string
    {
        if (! $enabled || trim($text) === '') {
            return '';
        }

        return self::cjkSpan(trim($text), $leading);
    }

    private static function cjkSpan(string $text, string $leading = ''): string
    {
        $family = TerimaBarangPdfFonts::FAMILY;

        return $leading.'<span class="cjk-block" style="font-family:\''.$family.'\';font-weight:normal;font-style:normal;text-transform:none;">'
            .htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</span>';
    }

    public static function cjkPreloadHtml(bool $enabled): string
    {
        if (! $enabled) {
            return '';
        }

        $family = TerimaBarangPdfFonts::FAMILY;
        $text = TerimaBarangPdfFonts::cjkPreloadString();

        return '<div style="position:fixed;left:-20000px;top:0;width:1px;height:1px;overflow:hidden;'
            .'font-family:\''.$family.'\',sans-serif;font-size:1px;line-height:1px;color:#fff;opacity:0;">'
            .htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            .'</div>';
    }

    public static function formatRp(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, '.', ',');
    }

    public static function formatBasePriceLabel(float $amount, string $unit): string
    {
        if ($amount <= 0) {
            return '-';
        }

        return self::formatRp($amount).' / '.$unit;
    }

    public static function formatRmb(float $amount): string
    {
        return '¥'.RmbFormat::format($amount);
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     */
    public static function formatItemsListMultiline(Collection $products, ?Barang $barang = null): string
    {
        $plain = self::formatItemsListPlain($products, $barang);
        if ($plain === '-' || ! str_contains($plain, ', ')) {
            return $plain;
        }

        return str_replace(', ', ",\n", $plain);
    }

    public static function formatNetWeightColumn(float $gwLine, float $gwTotal): string
    {
        if ($gwTotal <= 0) {
            return '-';
        }

        $ratio = 0.948;
        $nwTotal = round($gwTotal * $ratio, 1);

        if ($gwLine <= 0) {
            return number_format($nwTotal, 1, '.', '');
        }

        $nwLine = round($gwLine * $ratio, 1);
        if (abs($gwLine - $gwTotal) < 0.01) {
            return number_format($nwTotal, 1, '.', '');
        }

        return number_format($nwLine, 1, '.', '')."\n".number_format($nwTotal, 1, '.', '');
    }

    public static function formatItemsListPlain(Collection $products, ?Barang $barang = null): string
    {
        $labels = self::collectProductLabels($products);

        if ($labels->isEmpty() && $barang !== null) {
            return self::formatFallbackItemsPlain($barang);
        }

        if ($labels->isEmpty()) {
            return '-';
        }

        return $labels->implode(', ');
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     * @return list<array{
     *     ctns: string,
     *     length: string,
     *     width: string,
     *     height: string,
     *     weight: string,
     *     vol: string,
     *     length_photo_uri: string|null,
     *     width_photo_uri: string|null,
     *     height_photo_uri: string|null,
     *     rowspan: int,
     *     items: list<array<string, mixed>>,
     * }>
     */
    public static function buildPackingRowGroups(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        /** @var list<array{ctns: string, length: string, width: string, height: string, weight: string, vol: string, rowspan: int, items: list<array<string, mixed>>}> $groups */
        $groups = [];
        /** @var array<string, int> $groupIndexByParent */
        $groupIndexByParent = [];

        foreach ($products->values() as $product) {
            $parentKey = (string) self::resolvePackingMergeParentId($product);
            $master = $product->logisticsSource();

            if (! isset($groupIndexByParent[$parentKey])) {
                $ctns = max(1, (int) $master->ctns);
                $lineCbm = (float) $master->volume_cbm;

                $groupIndexByParent[$parentKey] = count($groups);
                $groups[] = [
                    'ctns' => (string) $ctns,
                    'length' => self::formatDimension((float) $master->length_cm),
                    'width' => self::formatDimension((float) $master->width_cm),
                    'height' => self::formatDimension((float) $master->height_cm),
                    'weight' => self::formatPackingGroupWeight($master),
                    'vol' => $lineCbm > 0 ? CbmFormat::format($lineCbm) : '-',
                    'length_photo_uri' => self::dimensionPhotoDataUri($master, 'length'),
                    'width_photo_uri' => self::dimensionPhotoDataUri($master, 'width'),
                    'height_photo_uri' => self::dimensionPhotoDataUri($master, 'height'),
                    'rowspan' => 0,
                    'items' => [],
                ];
            }

            $groups[$groupIndexByParent[$parentKey]]['items'][] = self::buildPackingItemRow($product, $master);
        }

        foreach ($groups as &$group) {
            $group['rowspan'] = count($group['items']);
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  list<array{
     *     length_photo_uri?: string|null,
     *     width_photo_uri?: string|null,
     *     height_photo_uri?: string|null,
     *     items?: list<array{photo_proof_uri?: string|null, photo_uri?: string|null}>
     * }>  $packingGroups
     */
    public static function hasDimensionProofPhotos(array $packingGroups): bool
    {
        foreach ($packingGroups as $group) {
            if (! empty($group['length_photo_uri'])
                || ! empty($group['width_photo_uri'])
                || ! empty($group['height_photo_uri'])) {
                return true;
            }

            foreach ($group['items'] ?? [] as $item) {
                if (! empty($item['photo_proof_uri']) || ! empty($item['photo_uri'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{items: list<array<string, mixed>>, ctns?: string, length?: string, width?: string, height?: string, weight?: string, vol?: string}>  $groups
     * @return list<array<string, mixed>>
     */
    public static function flattenPackingGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $index => $item) {
                $rows[] = $index === 0
                    ? array_merge($item, [
                        'ctns' => (string) ($group['ctns'] ?? ''),
                        'length' => (string) ($group['length'] ?? '-'),
                        'width' => (string) ($group['width'] ?? '-'),
                        'height' => (string) ($group['height'] ?? '-'),
                        'weight' => (string) ($group['weight'] ?? '-'),
                        'vol' => (string) ($group['vol'] ?? '-'),
                    ])
                    : array_merge($item, [
                        'ctns' => '',
                        'length' => '',
                        'width' => '',
                        'height' => '',
                        'weight' => '',
                        'vol' => '',
                    ]);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     * @return list<array<string, mixed>>
     */
    public static function buildPackingRows(Collection $products): array
    {
        return self::flattenPackingGroups(self::buildPackingRowGroups($products));
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPackingItemRow(BarangProduct $product, BarangProduct $master): array
    {
        $ctns = max(1, (int) $master->ctns);
        $name = trim((string) ($product->product_name ?? ''));
        $material = trim((string) ($master->material ?? ''));
        $pcsPerBox = max(0, (int) $product->pcs_per_box);
        $lineTotalQty = $product->isSubItem()
            ? ($pcsPerBox > 0 ? $pcsPerBox : $ctns)
            : ($pcsPerBox > 0 ? $pcsPerBox * $ctns : $ctns);

        $unitPrice = (float) $product->price_per_unit_rmb;
        $amount = (float) $product->total_price_rmb;
        if ($amount <= 0 && $unitPrice > 0 && $lineTotalQty > 0) {
            $amount = RmbFormat::lineTotal($unitPrice, $lineTotalQty);
        }

        return [
            'description' => $name !== '' ? $name : '-',
            'description_sub' => $material !== '' && $material !== $name ? $material : '',
            'photo_uri' => TerimaBarangPdfImages::fitDataUri(
                self::absolutePublicPath($product->normalizedPhotoPath()),
                TerimaBarangPdfImages::THUMB_W,
                TerimaBarangPdfImages::THUMB_H,
            ),
            'photo_proof_uri' => TerimaBarangPdfImages::fitDataUri(
                self::absolutePublicPath($product->normalizedPhotoPath()),
                TerimaBarangPdfImages::PROOF_THUMB_W,
                TerimaBarangPdfImages::PROOF_THUMB_H,
            ),
            'quantity_per_box' => $pcsPerBox > 0 ? (string) $pcsPerBox : '-',
            'line_total_qty' => $lineTotalQty,
            'unit_price' => $unitPrice > 0 ? self::formatRmb($unitPrice) : '-',
            'amount' => $amount > 0 ? self::formatRmb($amount) : '-',
        ];
    }

    public static function formatPackingGroupWeight(BarangProduct $master): string
    {
        $totalKg = $master->lineGrossWeightKg();

        return $totalKg > 0
            ? number_format($totalKg, 1, '.', '')
            : '-';
    }

    private static function resolvePackingMergeParentId(BarangProduct $product): int
    {
        return $product->isSubItem()
            ? (int) $product->parent_id
            : (int) $product->id;
    }

    /**
     * @param  list<array{ctns: string, items: list<array<string, mixed>>}>  $groups
     * @return array{ctns: int, qty: int, amount: float}
     */
    public static function summarizePackingRowGroups(array $groups): array
    {
        $ctns = 0;
        $qty = 0;
        $amount = 0.0;

        foreach ($groups as $group) {
            $ctns += (int) ($group['ctns'] ?? 0);

            foreach ($group['items'] as $item) {
                $qty += (int) ($item['line_total_qty'] ?? 0);
                $amountStr = str_replace(['¥', ','], '', (string) ($item['amount'] ?? ''));
                $amount += (float) $amountStr;
            }
        }

        return [
            'ctns' => $ctns,
            'qty' => $qty,
            'amount' => $amount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ctns: int, qty: int, amount: float}
     */
    public static function summarizePackingRows(array $rows): array
    {
        $ctns = 0;
        $qty = 0;
        $amount = 0.0;

        foreach ($rows as $row) {
            if (($row['ctns'] ?? '') !== '') {
                $ctns += (int) $row['ctns'];
            }
            $qty += (int) ($row['line_total_qty'] ?? 0);
            $amountStr = str_replace(['¥', ','], '', (string) ($row['amount'] ?? ''));
            $amount += (float) $amountStr;
        }

        return [
            'ctns' => $ctns,
            'qty' => $qty,
            'amount' => $amount,
        ];
    }

    /**
     * @return list<array{
     *     items_display: string,
     *     coly: string,
     *     length: string,
     *     width: string,
     *     height: string,
     *     volume: string,
     *     weight: string,
     *     notes: string,
     *     base_price_label: string,
     *     price_label: string,
     * }>
     */
    public static function buildReceiptSummaryRows(Barang $barang): array
    {
        $pricing = BarangShippingPricing::calculate($barang);
        $itemsBase = self::formatItemsListMultiline($barang->products, $barang);
        $primaryItems = $itemsBase;
        $secondaryItems = $itemsBase;
        $coly = (string) self::resolveColyCount($barang);
        $totalCbm = CbmFormat::format((float) $barang->total_cbm);
        $threshold = BarangShippingPricing::normalizeThresholdPerCbmKg((float) $barang->weight_threshold_kg);
        $weightDisplay = self::formatWeightColumn($barang);
        $cbmBaseLabel = self::formatBasePriceLabel((float) $barang->price_per_cbm, 'CBM');
        $kgBaseLabel = self::formatBasePriceLabel((float) $barang->weight_price, 'KG');
        $dims = self::primaryProductDimensions($barang);
        $blankDims = ['length' => '-', 'width' => '-', 'height' => '-'];

        if ($pricing['method'] === BarangShippingPricing::METHOD_SPLIT) {
            $capLabel = $threshold > 0
                ? CbmFormat::format($threshold)
                : $totalCbm;

            return [
                array_merge($dims, [
                    'items_display' => $primaryItems,
                    'coly' => $coly,
                    'volume' => $capLabel,
                    'weight' => $weightDisplay,
                    'notes' => self::resolveReceiptLineNotes(
                        'CBM',
                        (float) $pricing['billable_cbm'],
                        (float) $barang->total_cbm,
                    ),
                    'base_price_label' => $cbmBaseLabel,
                    'price_label' => self::formatRp($pricing['cbm_portion_idr']),
                ]),
                array_merge($blankDims, [
                    'items_display' => $secondaryItems,
                    'coly' => '-',
                    'volume' => '-',
                    'weight' => self::formatSplitValue((float) $pricing['excess_kg']),
                    'notes' => 'KG',
                    'base_price_label' => $kgBaseLabel,
                    'price_label' => self::formatRp($pricing['weight_portion_idr']),
                ]),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT) {
            $billableCbm = CbmFormat::format((float) $pricing['billable_cbm']);
            $volume = $billableCbm !== $totalCbm
                ? $billableCbm.' ('.$totalCbm.')'
                : $totalCbm;
            $capKg = (float) ($pricing['cap_kg'] ?? 0);
            $capWeightDisplay = $capKg > 0
                ? self::formatSplitValue($capKg)
                : $weightDisplay;

            return [
                array_merge($dims, [
                    'items_display' => $primaryItems,
                    'coly' => $coly,
                    'volume' => $volume,
                    'weight' => $capWeightDisplay,
                    'notes' => self::resolveReceiptLineNotes(
                        'CBM',
                        (float) $pricing['billable_cbm'],
                        (float) $barang->total_cbm,
                    ),
                    'base_price_label' => $cbmBaseLabel,
                    'price_label' => self::formatRp($pricing['cbm_portion_idr']),
                ]),
                array_merge($blankDims, [
                    'items_display' => $secondaryItems,
                    'coly' => '-',
                    'volume' => '-',
                    'weight' => self::formatSplitValue((float) $pricing['excess_kg']),
                    'notes' => 'KG',
                    'base_price_label' => $kgBaseLabel,
                    'price_label' => self::formatRp($pricing['weight_portion_idr']),
                ]),
            ];
        }

        if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT) {
            return [
                array_merge($dims, [
                    'items_display' => $primaryItems,
                    'coly' => $coly,
                    'volume' => $totalCbm,
                    'weight' => self::formatWeightColumn($barang, true),
                    'notes' => 'KG',
                    'base_price_label' => $kgBaseLabel,
                    'price_label' => self::formatRp($pricing['weight_portion_idr']),
                ]),
            ];
        }

        $billableCbm = CbmFormat::format($pricing['billable_cbm']);
        $volume = $billableCbm !== $totalCbm
            ? $billableCbm.' ('.$totalCbm.')'
            : $totalCbm;

        return [
            array_merge($dims, [
                'items_display' => $primaryItems,
                'coly' => $coly,
                'volume' => $volume,
                'weight' => $weightDisplay,
                'notes' => self::resolveReceiptLineNotes(
                    'CBM',
                    (float) $pricing['billable_cbm'],
                    (float) $barang->total_cbm,
                ),
                'base_price_label' => $cbmBaseLabel,
                'price_label' => self::formatRp($pricing['cbm_portion_idr']),
            ]),
        ];
    }

    public static function resolveReceiptLineNotes(string $priceTypeLabel, float $billableCbm, float $actualCbm): string
    {
        if ($billableCbm > CbmFormat::truncate(max(0, $actualCbm))) {
            return 'min charge';
        }

        return $priceTypeLabel;
    }

    private static function formatSplitValue(float $value): string
    {
        if ($value <= 0) {
            return '-';
        }

        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Extra charge lines (Packing, Goni, …) from Additional Price on the barang form.
     *
     * @return list<array{
     *     items_display: string,
     *     coly: string,
     *     length: string,
     *     width: string,
     *     height: string,
     *     volume: string,
     *     weight: string,
     *     notes: string,
     *     base_price_label: string,
     *     price_label: string,
     * }>
     */
    public static function buildReceiptAdditionalRows(Barang $barang): array
    {
        if (! $barang->relationLoaded('additionalPrices')) {
            $barang->loadMissing([
                'additionalPrices' => static fn ($q) => $q->orderBy('sort_order'),
            ]);
        }

        $rows = [];

        foreach ($barang->additionalPrices as $additional) {
            $name = trim((string) ($additional->name ?? ''));
            $price = (float) ($additional->price ?? 0);

            if ($name === '' && $price <= 0) {
                continue;
            }

            $rows[] = [
                'items_display' => $name !== '' ? $name : '-',
                'coly' => '',
                'length' => '',
                'width' => '',
                'height' => '',
                'volume' => '',
                'weight' => '',
                'notes' => '',
                'base_price_label' => '-',
                'price_label' => $price > 0 ? self::formatRp($price) : '-',
            ];
        }

        return $rows;
    }

    public static function formatPriceRateSuffix(Barang $barang): string
    {
        $kgPrice = (float) $barang->weight_price;
        $cbmPrice = (float) $barang->price_per_cbm;

        if ($kgPrice <= 0 && $cbmPrice <= 0) {
            return '';
        }

        $kgPart = $kgPrice > 0 ? self::formatRp($kgPrice) : '-';
        $cbmPart = $cbmPrice > 0 ? self::formatRp($cbmPrice) : '-';

        return '('.$kgPart.'/'.$cbmPart.')';
    }

    public static function appendItemsWithPriceRates(string $items, string $rateSuffix): string
    {
        if ($rateSuffix === '') {
            return $items;
        }

        if ($items === '-' || trim($items) === '') {
            return $rateSuffix;
        }

        return $items."\n".$rateSuffix;
    }

    public static function formatPricingNotes(Barang $barang, float $cbmValue): string
    {
        $lines = ['CBM: '.CbmFormat::format($cbmValue)];

        $kgPrice = (float) $barang->weight_price;
        $cbmPrice = (float) $barang->price_per_cbm;

        if ($cbmPrice > 0) {
            $lines[] = 'Rp/CBM: '.self::formatRp($cbmPrice);
        }

        if ($kgPrice > 0) {
            $lines[] = 'Rp/kg: '.self::formatRp($kgPrice);
        }

        return implode("\n", $lines);
    }

    public static function resolveNotesDisplay(Barang $barang): string
    {
        $notes = trim((string) ($barang->notes ?? ''));
        if ($notes !== '') {
            return $notes;
        }

        $type = trim((string) ($barang->cbm_type ?? ''));
        if ($type === '') {
            return '-';
        }

        if (preg_match('/cubic/i', $type) === 1) {
            return $type;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $type) === 1) {
            return 'Cubic';
        }

        if (str_contains(strtolower($type), 'cubic')) {
            return 'Cubic';
        }

        return $type;
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     */
    public static function formatItemsListHtml(Collection $products): string
    {
        $labels = self::collectProductLabels($products);

        if ($labels->isEmpty()) {
            return e('-');
        }

        return $labels
            ->map(static fn (string $name) => '"'.e($name, false).'"')
            ->implode(', ');
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     * @return Collection<int, string>
     */
    private static function collectProductLabels(Collection $products): Collection
    {
        $labels = $products
            ->map(static function (BarangProduct $product): string {
                $name = trim((string) ($product->product_name ?? ''));
                if ($name !== '') {
                    return $name;
                }

                return trim((string) ($product->material ?? ''));
            })
            ->filter(static fn (string $label) => $label !== '')
            ->values();

        if ($labels->isNotEmpty()) {
            return $labels;
        }

        return collect();
    }

    private static function resolveColyCount(Barang $barang): int
    {
        if ($barang->relationLoaded('products') && $barang->products->isNotEmpty()) {
            $fromProducts = (int) $barang->products
                ->filter(static fn (BarangProduct $p): bool => ! $p->isSubItem())
                ->sum(static fn (BarangProduct $p): int => (int) $p->ctns);

            if ($fromProducts > 0) {
                return $fromProducts;
            }
        }

        return (int) ($barang->ctns ?? 0);
    }

    /**
     * @return array{length: string, width: string, height: string}
     */
    public static function primaryProductDimensions(Barang $barang): array
    {
        if (! $barang->relationLoaded('products')) {
            $barang->loadMissing([
                'products' => static fn ($q) => $q->orderBy('sort_order')->with('parent'),
            ]);
        }

        $product = $barang->products->sortBy('sort_order')->firstWhere('parent_id', null)
            ?? $barang->products->sortBy('sort_order')->first();

        if ($product === null) {
            return ['length' => '-', 'width' => '-', 'height' => '-'];
        }

        $logistics = $product->logisticsSource();

        return [
            'length' => self::formatDimension((float) $logistics->length_cm),
            'width' => self::formatDimension((float) $logistics->width_cm),
            'height' => self::formatDimension((float) $logistics->height_cm),
        ];
    }

    public static function formatDimension(float $cm): string
    {
        if ($cm <= 0) {
            return '-';
        }

        if (abs($cm - round($cm)) < 0.01) {
            return number_format($cm, 0, '.', '');
        }

        return rtrim(rtrim(number_format($cm, 2, '.', ''), '0'), '.');
    }

    /**
     * Berat yang ditampilkan di PDF: berat kotor aktual, atau batas total (CBM × max/kg) saat split berat.
     */
    public static function formatWeightColumn(Barang $barang, bool $forceGross = false): string
    {
        $gw = max(0, (float) $barang->total_gross_weight);

        if (! $forceGross) {
            $pricing = BarangShippingPricing::calculate($barang);
            $capKg = (float) ($pricing['cap_kg'] ?? 0);
            if ($pricing['method'] === BarangShippingPricing::METHOD_WEIGHT_SPLIT && $capKg > 0) {
                return self::formatSplitValue($capKg);
            }
        }

        if ($gw <= 0) {
            return '-';
        }

        return self::formatSplitValue($gw);
    }

    private static function formatFallbackItemsPlain(Barang $barang): string
    {
        foreach ([$barang->form ?? null, $barang->material_desc ?? null] as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return '-';
    }

    public static function filename(Barang $barang): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($barang->no_index ?? 'terima-barang')) ?: 'terima-barang';

        return "{$base}.pdf";
    }

    private static function resolveLogoPath(): ?string
    {
        $branding = \App\Support\Branding::absoluteLogoPath();
        if ($branding !== null && is_file($branding)) {
            return $branding;
        }

        $fallback = public_path('images/terima-ref-0.png');

        return is_file($fallback) ? $fallback : null;
    }

    private static function resolveTrackingNumber(Barang $barang): string
    {
        $explicit = trim((string) ($barang->tracking_number ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $resi = $barang->no_resi;
        if (is_array($resi)) {
            $first = collect($resi)->filter(static fn ($v) => is_string($v) && trim($v) !== '')->first();
            if (is_string($first)) {
                return trim($first);
            }
        }

        if (is_string($resi) && trim($resi) !== '') {
            return trim($resi);
        }

        return '';
    }

    private static function pathRelativeToBase(string $absolutePath): string
    {
        $base = str_replace('\\', '/', base_path());
        $path = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private static function fontFileUri(string $absolutePath): string
    {
        $path = str_replace('\\', '/', $absolutePath);

        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            return 'file:///'.$path;
        }

        return 'file://'.$path;
    }

    private static function absolutePublicPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $full = storage_path('app/public/'.ltrim(str_replace('\\', '/', $path), '/'));

        return is_file($full) ? $full : null;
    }

    private static function dimensionPhotoDataUri(BarangProduct $product, string $dimension): ?string
    {
        $path = match ($dimension) {
            'length' => BarangProduct::normalizeStoragePath($product->length_photo_path),
            'width' => BarangProduct::normalizeStoragePath($product->width_photo_path),
            'height' => BarangProduct::normalizeStoragePath($product->height_photo_path),
            default => null,
        };

        return TerimaBarangPdfImages::fitDataUri(
            self::absolutePublicPath($path),
            TerimaBarangPdfImages::PROOF_THUMB_W,
            TerimaBarangPdfImages::PROOF_THUMB_H,
        );
    }

    private static function isImagePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
