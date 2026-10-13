<?php

declare(strict_types=1);

namespace App\Support;

final class TerimaBarangPdfImages
{
    public const RECEIPT_W = 290;

    public const RECEIPT_H = 220;

    public const THUMB_W = 140;

    public const THUMB_H = 140;

    /** Dimension proof page only — larger embed for DomPDF display. */
    public const PROOF_THUMB_W = 220;

    public const PROOF_THUMB_H = 220;

    public const INVOICE_MAX_W = 1200;

    public const INVOICE_MAX_H = 1700;

    public static function fitDataUri(?string $absolutePath, int $boxW, int $boxH, int $jpegQuality = 88): ?string
    {
        if ($absolutePath === null || $absolutePath === '' || ! is_file($absolutePath)) {
            return null;
        }

        if (! extension_loaded('gd')) {
            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        $info = @getimagesize($absolutePath);
        if ($info === false) {
            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        $src = self::loadImage($absolutePath, (string) ($info['mime'] ?? ''));
        if ($src === null) {
            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($src);

            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        $scale = min($boxW / $srcW, $boxH / $srcH, 1.0);
        $newW = max(1, (int) round($srcW * $scale));
        $newH = max(1, (int) round($srcH * $scale));

        $canvas = imagecreatetruecolor($boxW, $boxH);
        if ($canvas === false) {
            imagedestroy($src);

            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $resized = imagecreatetruecolor($newW, $newH);
        if ($resized === false) {
            imagedestroy($src);
            imagedestroy($canvas);

            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $offsetX = (int) floor(($boxW - $newW) / 2);
        $offsetY = (int) floor(($boxH - $newH) / 2);
        imagecopy($canvas, $resized, $offsetX, $offsetY, 0, 0, $newW, $newH);

        ob_start();
        imagejpeg($canvas, null, max(60, min(95, $jpegQuality)));
        $binary = ob_get_clean();

        imagedestroy($src);
        imagedestroy($resized);
        imagedestroy($canvas);

        if ($binary === false || $binary === '') {
            return TerimaBarangPdfData::imageDataUri($absolutePath);
        }

        return 'data:image/jpeg;base64,'.base64_encode($binary);
    }

    /**
     * @return \GdImage|resource|null
     */
    private static function loadImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            'image/gif' => @imagecreatefromgif($path) ?: null,
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path) ?: null,
            default => @imagecreatefromjpeg($path)
                ?: (@imagecreatefrompng($path) ?: null),
        };
    }
}
