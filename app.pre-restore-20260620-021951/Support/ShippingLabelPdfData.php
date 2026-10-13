<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Marking;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

final class ShippingLabelPdfData
{
    /** Corner badge on shipping label (black & white). */
    public const CORNER_LOGO_PUBLIC = 'images/logo bnw.png';

  /** Paper size: 100mm × 150mm (DomPDF points). */
    public const PAPER_WIDTH_PT = 283.46;

    public const PAPER_HEIGHT_PT = 425.20;

    /**
     * @return array<string, mixed>
     */
    public static function forBarang(Barang $barang): array
    {
        $barang->loadMissing([
            'products' => static fn ($q) => $q->orderBy('sort_order'),
            'products.parent',
            'packing',
            'gudangChina',
            'customer',
        ]);

        $marking = self::resolveMarking($barang);
        $noIndex = trim((string) ($barang->no_index ?? ''));
        $items = self::buildItemRows($barang);
        $totalColy = (int) ($barang->ctns > 0
            ? $barang->ctns
            : collect($items)->sum(static fn (array $row) => (int) ($row['coly_raw'] ?? 0)));

        return [
            'serviceBadge' => 'FWD',
            'cornerLogoUri' => TerimaBarangPdfData::imageDataUri(self::cornerLogoPath()),
            'warehouseOrigin' => self::resolveWarehouseOriginShort($barang),
            'receiptDate' => $barang->tanggal_masuk?->format('d/m/Y') ?? now()->format('d/m/Y'),
            'customerName' => self::resolveCustomerName($barang, $marking),
            'customerAddress' => self::resolveCustomerAddress($barang, $marking),
            'markingLabel' => trim((string) ($barang->marking ?? '')) ?: '-',
            'markingDisplay' => self::formatMarkingForLabel(trim((string) ($barang->marking ?? ''))),
            'notesLabel' => self::resolveNotes($barang),
            'packingLabel' => trim((string) ($barang->packing?->name ?? '')) ?: '-',
            'items' => $items,
            'totalColy' => max(0, $totalColy),
            'noIndex' => $noIndex !== '' ? $noIndex : '-',
            'headerBarcodeDataUri' => self::barcodePngDataUri($noIndex, 2, 28),
            'headerBarcodePath' => self::barcodePngAbsolutePath($noIndex, 2, 28),
            'headerBarcodeTecItUrl' => self::barcodeTecItUrl($noIndex),
            'barcodeSvg' => self::barcodeSvg($noIndex),
            'barcodeDataUri' => self::barcodePngDataUri($noIndex),
            'barcodeTecItUrl' => self::barcodeTecItUrl($noIndex),
        ];
    }

    public static function filename(Barang $barang): string
    {
        $slug = str(trim((string) ($barang->no_index ?? 'label')))->slug()->toString();

        return 'label-'.$slug.'.pdf';
    }

    private static function resolveWarehouseOriginShort(Barang $barang): string
    {
        $origin = trim((string) ($barang->warehouse_origin ?? ''));
        if ($origin !== '') {
            return self::shortOriginLabel($origin);
        }

        $gudang = trim((string) ($barang->gudangChina?->name ?? ''));
        if ($gudang !== '') {
            return self::shortOriginLabel($gudang);
        }

        return 'Guangzhou';
    }

    private static function shortOriginLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        return (string) preg_replace('/\s+warehouse$/i', '', $value);
    }

    private static function resolveCustomerName(Barang $barang, ?Marking $marking): string
    {
        $fromBarang = trim((string) ($barang->customer_name ?? ''));
        if ($fromBarang !== '') {
            return $fromBarang;
        }

        $fromMarking = trim((string) ($marking?->customer_name ?? ''));
        if ($fromMarking !== '') {
            return $fromMarking;
        }

        return trim((string) ($barang->customer?->name ?? '')) ?: '-';
    }

    private static function resolveCustomerAddress(Barang $barang, ?Marking $marking): string
    {
        $userAddress = trim((string) ($barang->customer?->address ?? ''));
        if ($userAddress !== '') {
            return $userAddress;
        }

        $parts = array_filter([
            trim((string) ($marking?->address_1 ?? '')),
            trim((string) ($marking?->address_2 ?? '')),
        ], static fn (string $p) => $p !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $dest = trim((string) ($barang->destination ?? ''));

        return $dest !== '' ? $dest : '-';
    }

    private static function resolveNotes(Barang $barang): string
    {
        $notes = trim((string) ($barang->notes ?? ''));
        if ($notes !== '') {
            return $notes;
        }

        $ket = trim((string) ($barang->keterangan ?? ''));

        return $ket !== '' ? $ket : '-';
    }

    private static function cornerLogoPath(): ?string
    {
        $path = public_path(self::CORNER_LOGO_PUBLIC);

        return is_file($path) ? $path : null;
    }

    private static function formatMarkingForLabel(string $marking): string
    {
        $marking = trim(preg_replace('/[\r\n]+/', '', $marking) ?? '');
        if ($marking === '') {
            return '-';
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('#\s*/\s*#', $marking) ?: []),
            static fn (string $p) => $p !== '',
        ));

        if (count($parts) <= 1) {
            return e($marking);
        }

        if (count($parts) === 2) {
            return e($parts[0]).'/'.e($parts[1]);
        }

        if (count($parts) === 3) {
            return e($parts[0]).'/'.e($parts[1]).'/'.'<br>'.e($parts[2]);
        }

        $lines = [];
        foreach (array_chunk($parts, (int) ceil(count($parts) / 3)) as $chunk) {
            $lines[] = implode('/', array_map(static fn (string $p) => e($p), $chunk));
        }

        return implode('<br>', array_slice($lines, 0, 3));
    }

    private static function resolveMarking(Barang $barang): ?Marking
    {
        $value = trim((string) ($barang->marking ?? ''));
        if ($value === '') {
            return null;
        }

        return Marking::query()
            ->whereBarangMarkingValue($value)
            ->first();
    }

    /**
     * @return list<array{description: string, gross_weight: string, coly: string, coly_raw: int}>
     */
    private static function buildItemRows(Barang $barang): array
    {
        $products = $barang->products;
        if ($products->isEmpty()) {
            return [[
                'description' => trim((string) ($barang->no_index ?? '')) ?: '-',
                'gross_weight' => self::formatKg((float) $barang->total_gross_weight),
                'coly' => (string) max(1, (int) $barang->ctns),
                'coly_raw' => max(1, (int) $barang->ctns),
            ]];
        }

        $rows = [];
        foreach ($products as $product) {
            $rows[] = self::itemRowFromProduct($product);
        }

        return $rows;
    }

    /**
     * @return array{description: string, gross_weight: string, coly: string, coly_raw: int}
     */
    private static function itemRowFromProduct(BarangProduct $product): array
    {
        $logistics = $product->logisticsSource();
        $name = trim((string) ($product->product_name ?? ''));
        $material = trim((string) ($product->material ?? ''));
        $description = $name !== '' ? $name : ($material !== '' ? $material : '-');

        if ($product->isSubItem()) {
            $pcs = max(0, (int) $product->pcs_per_box);

            return [
                'description' => $description,
                'gross_weight' => '-',
                'coly' => $pcs > 0 ? (string) $pcs : '1',
                'coly_raw' => $pcs > 0 ? $pcs : 1,
            ];
        }

        $gw = $logistics->lineGrossWeightKg();
        $ctns = max(1, (int) $product->ctns);

        return [
            'description' => $description,
            'gross_weight' => $gw > 0 ? self::formatKg($gw) : '-',
            'coly' => (string) $ctns,
            'coly_raw' => $ctns,
        ];
    }

    private static function formatKg(float $kg): string
    {
        return number_format($kg, 2, '.', '').' kg';
    }

    private static function barcodeSvg(string $noIndex, float $widthFactor = 2.2, int $height = 44): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-') {
            return null;
        }

        $width = max(1, (int) round($widthFactor));

        try {
            $generator = new BarcodeGeneratorSVG();
            $svg = $generator->getBarcode($code, $generator::TYPE_CODE_128, $width, $height);

            return $svg;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function barcodePngDataUri(string $noIndex, float $widthFactor = 2.4, int $height = 52): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-') {
            return null;
        }

        $width = max(1, (int) round($widthFactor));
        $height = max(20, $height);

        try {
            $generator = new BarcodeGeneratorPNG();
            $png = $generator->getBarcode($code, $generator::TYPE_CODE_128, $width, $height);

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function barcodeTecItUrl(string $noIndex): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-') {
            return null;
        }

        return 'https://barcode.tec-it.com/barcode.ashx?data='.rawurlencode($code)
            .'&code=Code128&dpi=96&imagetype=Png&height=8&translate-esc=on&showhrt=no';
    }

    /**
     * Absolute PNG path for DomPDF (fallback when data URI fails).
     */
    private static function barcodePngAbsolutePath(string $noIndex, float $widthFactor = 2.4, int $height = 52): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-') {
            return null;
        }

        $width = max(1, (int) round($widthFactor));
        $height = max(20, $height);

        try {
            $generator = new BarcodeGeneratorPNG();
            $png = $generator->getBarcode($code, $generator::TYPE_CODE_128, $width, $height);
        } catch (\Throwable) {
            return null;
        }

        $dir = storage_path('app/pdf-barcodes');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = 'label-'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $code).'.png';
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        if (file_put_contents($path, $png) === false) {
            return null;
        }

        return str_replace('\\', '/', $path);
    }

}
