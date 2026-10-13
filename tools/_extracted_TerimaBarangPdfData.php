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
        ]);

        $marking = null;
        if ($barang->marking !== null && $barang->marking !== '') {
            $marking = Marking::visibleToUser()
                ->with('sales:id,name')
                ->whereBarangMarkingValue($barang->marking)
                ->first();
        }

        $trackingNumber = self::resolveTrackingNumber($barang);
        $totalIdr = self::estimatedShippingTotalIdr($barang);
        $volumeValue = number_format((float) $barang->total_cbm, 1, '.', '');
        $weightValue = number_format((float) $barang->total_gross_weight, 0, '.', '');

        return [
            'barang' => $barang,
            'marking' => $marking,
            'markingCode' => $barang->marking ?? '-',
            'marketingName' => $marking?->sales?->name ?? '-',
            'trackingNumber' => $trackingNumber !== '' ? $trackingNumber : '-',
            'supplierDisplay' => trim((string) ($barang->supplier_chinese ?? '')) !== '' ? $barang->supplier_chinese : '-',
            'entryDateLabel' => $barang->tanggal_masuk?->format('j-M-Y') ?? '-',
            'itemsText' => self::formatItemsList($barang->products),
            'volumeValue' => $volumeValue,
            'weightValue' => $weightValue,
            'notesDisplay' => trim((string) ($barang->notes ?? '')) !== '' ? $barang->notes : 'Cubic',
            'priceLabel' => self::formatRp((float) ($barang->price ?? 0)),
            'totalLabel' => self::formatRp($totalIdr),
            'logoPath' => self::resolveLogoPath(),
            'goodReceiptPath' => self::absolutePublicPath($barang->good_receipt_path),
            'packingListPath' => self::absolutePublicPath($barang->invoice_file),
            'packingListIsImage' => self::isImagePath($barang->invoice_file),
        ];
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

    /**
     * @param  Collection<int, BarangProduct>  $products
     */
    private static function formatItemsList(Collection $products): string
    {
        $names = $products
            ->map(static fn ($p) => trim((string) ($p->product_name ?? '')))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return '-';
        }

        return $names->map(static fn (string $name) => '"'.$name.'"')->implode(', ');
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
