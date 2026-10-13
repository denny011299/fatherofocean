<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Support\Facades\Http;
use Picqer\Barcode\BarcodeGeneratorPNG;

final class ShippingLabelPdfData
{
    /** Paper size: 104mm × 30mm (DomPDF points). */
    public const PAPER_WIDTH_PT = 294.80;

    public const PAPER_HEIGHT_PT = 85.04;

    /**
     * @return array<string, mixed>
     */
    public static function forBarang(Barang $barang): array
    {
        $barang->loadMissing([
            'products' => static fn ($q) => $q->orderBy('sort_order'),
            'products.parent',
        ]);

        $noIndex = trim((string) ($barang->no_index ?? ''));
        $items = self::buildItemRows($barang);
        $totalColy = max(1, (int) ($barang->ctns > 0
            ? $barang->ctns
            : collect($items)->sum(static fn (array $row) => (int) ($row['coly_raw'] ?? 0))));

        $markingLabel = trim((string) ($barang->marking ?? '')) ?: '-';
        $indexLabel = $noIndex !== '' ? $noIndex : '-';
        $barcodePng = self::barcodePngBytes($noIndex, 2.0, 48);
        $barcodePath = self::storeBarcodePng($noIndex, $barcodePng);
        $barcodeDataUri = $barcodePng !== null
            ? 'data:image/png;base64,'.base64_encode($barcodePng)
            : null;
        $barcodeTecItUrl = self::barcodeTecItUrl($noIndex, 10);

        $labels = [];
        for ($i = 1; $i <= $totalColy; $i++) {
            $colyDisplay = $i.'/'.$totalColy.' coly';
            $labels[] = [
                'coly_current' => $i,
                'coly_total' => $totalColy,
                'coly_display' => $colyDisplay,
                'image' => self::renderLabelImageDataUri($indexLabel, $markingLabel, $colyDisplay),
            ];
        }

        return [
            'labels' => $labels,
            'markingLabel' => $markingLabel,
            'noIndex' => $indexLabel,
            'barcodePath' => $barcodePath,
            'barcodeDataUri' => $barcodeDataUri,
            'barcodeTecItUrl' => $barcodeTecItUrl,
        ];
    }

    public static function filename(Barang $barang): string
    {
        $slug = str(trim((string) ($barang->no_index ?? 'label')))->slug()->toString();

        return 'label-'.$slug.'.pdf';
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

    /**
     * Render label (garis + teks). Barcode ditampilkan terpisah di view PDF.
     */
    public static function renderLabelImageDataUri(string $noIndex, string $markingLabel, string $colyDisplay): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $scale = 3;
        $width = 295 * $scale;
        $height = 85 * $scale;

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return null;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        imagerectangle($image, (int) ($scale / 2), (int) ($scale / 2), $width - (int) ($scale / 2) - 1, $height - (int) ($scale / 2) - 1, $black);

        $lineY1 = 37 * $scale;
        $lineY2 = 59 * $scale;
        $splitX = (int) (147.5 * $scale);
        imageline($image, 0, $lineY1, $width, $lineY1, $black);
        imageline($image, 0, $lineY2, $width, $lineY2, $black);
        imageline($image, $splitX, $lineY2, $splitX, $height, $black);

        $fontSize = 9 * $scale;
        self::drawCenteredText($image, $markingLabel, (int) (147.5 * $scale), (int) (52 * $scale), $fontSize, $black);
        self::drawCenteredText($image, $noIndex, (int) (73.5 * $scale), (int) (75 * $scale), $fontSize, $black);
        self::drawCenteredText($image, $colyDisplay, (int) (221 * $scale), (int) (75 * $scale), $fontSize, $black);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        if (! is_string($png) || $png === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private static function drawCenteredText(\GdImage $image, string $text, int $centerX, int $centerY, int $fontSize, int $color): void
    {
        $font = self::labelFontPath();
        if ($font === null) {
            $textWidth = imagefontwidth(3) * strlen($text);
            imagestring($image, 3, max(0, $centerX - (int) ($textWidth / 2)), max(0, $centerY - 8), $text, $color);

            return;
        }

        $box = imagettfbbox($fontSize, 0, $font, $text);
        if ($box === false) {
            return;
        }

        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        $x = (int) ($centerX - ($textWidth / 2));
        $baselineY = (int) ($centerY + ($textHeight / 2) - $box[1]);
        imagettftext($image, $fontSize, 0, $x, $baselineY, $color, $font, $text);
    }

    private static function labelFontPath(): ?string
    {
        foreach ([
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function barcodePngBytes(string $noIndex, float $widthFactor = 2.4, int $height = 52): ?string
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
            if (is_string($png) && $png !== '') {
                return $png;
            }
        } catch (\Throwable) {
            // Picqer can fail on some hosts; try remote fallback below.
        }

        return self::barcodePngBytesFromTecIt($code, 10);
    }

    private static function barcodePngBytesFromTecIt(string $code, int $heightMm = 10): ?string
    {
        $url = self::barcodeTecItUrl($code, $heightMm);
        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get($url);
            if ($response->successful()) {
                $body = $response->body();
                if (self::isPngBytes($body)) {
                    return $body;
                }
            }
        } catch (\Throwable) {
            // Continue to fopen fallback.
        }

        if (! ini_get('allow_url_fopen')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'user_agent' => 'Oceanlink/1.0',
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return self::isPngBytes($body) ? $body : null;
    }

    private static function isPngBytes(mixed $body): bool
    {
        return is_string($body) && $body !== '' && str_starts_with($body, "\x89PNG");
    }

    private static function storeBarcodePng(string $noIndex, ?string $png): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-' || $png === null || $png === '') {
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

    private static function barcodeTecItUrl(string $noIndex, int $heightMm = 8): ?string
    {
        $code = trim($noIndex);
        if ($code === '' || $code === '-') {
            return null;
        }

        return 'https://barcode.tec-it.com/barcode.ashx?data='.rawurlencode($code)
            .'&code=Code128&dpi=96&imagetype=Png&height='.$heightMm.'&translate-esc=on&showhrt=no';
    }

}
