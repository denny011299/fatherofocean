<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
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
            'products' => static fn ($q) => $q->orderBy('sort_order'),
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
        $packingRows = self::buildPackingRows($barang->products);
        if ($productLabels->isEmpty()) {
            $itemsPlain = self::formatFallbackItemsPlain($barang);
            $productLabels = collect(preg_split('/\s*,\s*/', $itemsPlain) ?: [])->filter()->values();
        }
        $shippingBaseIdr = self::estimatedShippingTotalIdr($barang);
        $storedPriceIdr = (float) ($barang->price ?? 0);
        $priceIdr = $storedPriceIdr > 0 ? $storedPriceIdr : $shippingBaseIdr;
        $additionalIdr = (float) $barang->additionalPrices->sum('price');
        $totalIdr = $priceIdr + $additionalIdr;
        $volumeValue = number_format((float) $barang->total_cbm, 1, '.', '');
        $colyCount = self::resolveColyCount($barang);

        return [
            'barang' => $barang,
            'marking' => $marking,
            'markingCode' => $barang->marking ?? '-',
            'marketingName' => $marking?->sales?->name ?? '-',
            'trackingNumber' => $trackingNumber !== '' ? $trackingNumber : '-',
            'supplierDisplay' => trim((string) ($barang->supplier_chinese ?? '')) !== '' ? $barang->supplier_chinese : '-',
            'entryDateLabel' => $barang->tanggal_masuk?->format('j-M-Y') ?? '-',
            'itemsPlain' => $itemsPlain,
            'volumeValue' => $volumeValue,
            'weightValue' => self::formatWeightColumn($barang),
            'notesDisplay' => self::resolveNotesDisplay($barang),
            'colyCount' => $colyCount,
            'priceLabel' => self::formatRp($priceIdr),
            'totalLabel' => self::formatRp($totalIdr),
            'logoPath' => self::imageDataUri(self::resolveLogoPath()),
            'goodReceiptPath' => self::imageDataUri(self::absolutePublicPath($barang->good_receipt_path)),
            'packingRows' => $packingRows,
            'packingTotals' => self::summarizePackingRows($packingRows),
            'invoiceImagePath' => self::imageDataUri(self::absolutePublicPath($barang->invoice_file)),
            'showPackingTable' => $packingRows !== [],
            'showInvoiceImagePage' => self::isImagePath($barang->invoice_file)
                && self::absolutePublicPath($barang->invoice_file) !== null,
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
                'hasCjkFont' => false,
            ];
        }

        TerimaBarangPdfFonts::preloadMetrics($cjkPath);
        $normalized = str_replace('\\', '/', $cjkPath);

        return [
            'pdfFontFamily' => 'DejaVu Sans',
            'cjkFontFamily' => TerimaBarangPdfFonts::FAMILY,
            'cjkFontPath' => $normalized,
            'cjkFontUri' => self::fontFileUri($normalized),
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
        $cbm = (float) $barang->total_cbm;
        $gw = (float) $barang->total_gross_weight;
        $priceCbm = (float) $barang->price_per_cbm;
        $weightPrice = (float) $barang->weight_price;
        $threshold = (float) $barang->weight_threshold_kg;

        if ($threshold > 0 && $gw > $threshold) {
            return round($gw * $weightPrice);
        }

        return round($cbm * $priceCbm);
    }

    public static function formatRp(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, '.', ',');
    }

    public static function formatRmb(float $amount): string
    {
        return '¥'.number_format($amount, 2, '.', ',');
    }

    /**
     * @param  Collection<int, BarangProduct>  $products
     */
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
     * @return list<array<string, mixed>>
     */
    public static function buildPackingRows(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $rows = [];
        $ctnCursor = 1;

        foreach ($products->values() as $index => $product) {
            $ctns = max(1, (int) $product->ctns);
            $ctnEnd = $ctnCursor + $ctns - 1;
            $ctnNo = $ctns > 1 ? "{$ctnCursor}-{$ctnEnd}" : (string) $ctnCursor;
            $ctnCursor = $ctnEnd + 1;

            $name = trim((string) ($product->product_name ?? ''));
            $material = trim((string) ($product->material ?? ''));
            $pcsPerBox = max(0, (int) $product->pcs_per_box);
            $qty = $pcsPerBox > 0 ? $pcsPerBox * $ctns : $ctns;

            $gwLine = (float) $product->gross_weight_kg;
            $gwTotal = $product->lineGrossWeightKg();
            $gwDisplay = $gwTotal > 0
                ? number_format($gwLine, 1, '.', '')."\n".number_format($gwTotal, 1, '.', '')
                : '-';

            $length = (float) $product->length_cm;
            $width = (float) $product->width_cm;
            $height = (float) $product->height_cm;
            $volLines = [];
            if ($length > 0 && $width > 0 && $height > 0) {
                $volLines[] = number_format($length, 0, '.', '');
                $volLines[] = number_format($width, 0, '.', '');
                $volLines[] = number_format($height, 0, '.', '');
            }
            $volLines[] = number_format((float) $product->volume_cbm, 2, '.', '');
            $volDisplay = implode("\n", $volLines);

            $unitPrice = (float) $product->price_per_unit_rmb;
            $amount = (float) $product->total_price_rmb;
            if ($amount <= 0 && $unitPrice > 0 && $qty > 0) {
                $amount = $unitPrice * $qty;
            }

            $model = $material !== ''
                ? mb_substr($material, 0, 24)
                : 'P'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            $rows[] = [
                'ctn_no' => $ctnNo,
                'ctns' => (string) $ctns,
                'model' => $model,
                'photo_path' => self::imageDataUri(self::absolutePublicPath($product->photo_path)),
                'description' => $name !== '' ? $name : '-',
                'description_sub' => $material !== '' && $material !== $name ? $material : '',
                'qty' => (string) $qty,
                'gw' => $gwDisplay,
                'vol' => $volDisplay,
                'unit_price' => $unitPrice > 0 ? self::formatRmb($unitPrice) : '-',
                'amount' => $amount > 0 ? self::formatRmb($amount) : '-',
            ];
        }

        return $rows;
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
            $ctns += (int) ($row['ctns'] ?? 0);
            $qty += (int) ($row['qty'] ?? 0);
            $amountStr = (string) ($row['amount'] ?? '');
            $amountStr = str_replace(['¥', ','], '', $amountStr);
            $amount += (float) $amountStr;
        }

        return [
            'ctns' => $ctns,
            'qty' => $qty,
            'amount' => $amount,
        ];
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
            $fromProducts = (int) $barang->products->sum(static fn (BarangProduct $p) => (int) $p->ctns);

            if ($fromProducts > 0) {
                return $fromProducts;
            }
        }

        return (int) ($barang->ctns ?? 0);
    }

    private static function formatWeightColumn(Barang $barang): string
    {
        $threshold = (float) $barang->weight_threshold_kg;
        if ($threshold > 0) {
            return number_format($threshold, 0, '.', '');
        }

        $gw = (float) $barang->total_gross_weight;
        if ($gw <= 0) {
            return '-';
        }

        if (abs($gw - round($gw)) < 0.01) {
            return number_format($gw, 0, '.', '');
        }

        return number_format($gw, 2, '.', '');
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

    private static function isImagePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
