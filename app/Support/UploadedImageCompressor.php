<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resize and re-encode uploaded photos before storage to speed up uploads and save disk space.
 */
final class UploadedImageCompressor
{
    public const MAX_EDGE = 1920;

    public const JPEG_QUALITY = 82;

    public const WEBP_QUALITY = 82;

    public const PNG_COMPRESSION = 6;

    /** Skip compression when the file is already small. */
    public const SKIP_BELOW_BYTES = 120_000;

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/pjpeg',
        'image/png',
        'image/webp',
    ];

    public static function isCompressible(UploadedFile $file): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        return in_array(strtolower((string) $file->getMimeType()), self::IMAGE_MIMES, true);
    }

    /** Target stored size for delivery-trip proof photos (5 MB). */
    public const DELIVERY_TARGET_MAX_BYTES = 5_242_880;

    public static function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        if (! self::shouldCompress($file)) {
            return $file->store($directory, $disk);
        }

        $binary = self::compressToBinary($file);
        if ($binary === null) {
            return $file->store($directory, $disk);
        }

        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.'.self::outputExtension($file);

        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    public static function storeForDeliveryTrip(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        if (! self::isCompressible($file)) {
            return $file->store($directory, $disk);
        }

        $binary = self::compressToBinaryForDelivery($file);
        if ($binary === null) {
            return $file->store($directory, $disk);
        }

        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.jpg';
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    /**
     * Compressed bytes for custom storage (e.g. branding logo).
     */
    public static function compressedContents(UploadedFile $file): ?string
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $original = @file_get_contents($path);
        if ($original === false) {
            return null;
        }

        if (! self::shouldCompress($file)) {
            return $original;
        }

        $binary = self::compressToBinary($file);

        return $binary ?? $original;
    }

    /**
     * Compress existing file already stored on disk.
     *
     * @return array{processed: bool, compressed: bool, bytes_before: int, bytes_after: int}
     */
    public static function compressStoredFile(string $path, string $disk = 'public'): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return [
                'processed' => false,
                'compressed' => false,
                'bytes_before' => 0,
                'bytes_after' => 0,
            ];
        }

        $absolute = Storage::disk($disk)->path($path);
        if (! is_file($absolute)) {
            return [
                'processed' => false,
                'compressed' => false,
                'bytes_before' => 0,
                'bytes_after' => 0,
            ];
        }

        $mime = (string) (Storage::disk($disk)->mimeType($path) ?? '');
        if (! in_array(strtolower($mime), self::IMAGE_MIMES, true)) {
            return [
                'processed' => false,
                'compressed' => false,
                'bytes_before' => 0,
                'bytes_after' => 0,
            ];
        }

        $before = (int) (filesize($absolute) ?: 0);
        if ($before <= 0 || $before < self::SKIP_BELOW_BYTES || ! extension_loaded('gd')) {
            return [
                'processed' => true,
                'compressed' => false,
                'bytes_before' => $before,
                'bytes_after' => $before,
            ];
        }

        $binary = self::compressBinaryFromPath($absolute, $mime, $before);
        if ($binary === null) {
            return [
                'processed' => true,
                'compressed' => false,
                'bytes_before' => $before,
                'bytes_after' => $before,
            ];
        }

        Storage::disk($disk)->put($path, $binary);
        $after = strlen($binary);

        return [
            'processed' => true,
            'compressed' => $after > 0 && $after < $before,
            'bytes_before' => $before,
            'bytes_after' => $after > 0 ? $after : $before,
        ];
    }

    private static function shouldCompress(UploadedFile $file): bool
    {
        return self::isCompressible($file) && $file->getSize() >= self::SKIP_BELOW_BYTES;
    }

    private static function compressToBinary(UploadedFile $file): ?string
    {
        try {
            $path = $file->getRealPath() ?: $file->getPathname();
            $mime = strtolower((string) $file->getMimeType());
            $size = (int) $file->getSize();

            return self::compressBinaryFromPath($path, $mime, $size);
        } catch (Throwable $e) {
            Log::warning('Image compression failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);

            return null;
        }
    }

    private static function compressToBinaryForDelivery(UploadedFile $file): ?string
    {
        try {
            $path = $file->getRealPath() ?: $file->getPathname();
            $mime = strtolower((string) $file->getMimeType());
            $size = (int) $file->getSize();

            if ($size < self::SKIP_BELOW_BYTES) {
                return null;
            }

            $attempts = [
                ['max_edge' => self::MAX_EDGE, 'quality' => self::JPEG_QUALITY],
                ['max_edge' => self::MAX_EDGE, 'quality' => 68],
                ['max_edge' => 1600, 'quality' => self::JPEG_QUALITY],
                ['max_edge' => 1280, 'quality' => 62],
                ['max_edge' => 1024, 'quality' => 55],
            ];

            $best = null;
            foreach ($attempts as $attempt) {
                $binary = self::compressBinaryFromPath(
                    $path,
                    $mime,
                    $size,
                    (int) $attempt['max_edge'],
                    (int) $attempt['quality'],
                    true,
                );
                if ($binary === null) {
                    continue;
                }
                if (strlen($binary) <= self::DELIVERY_TARGET_MAX_BYTES) {
                    return $binary;
                }
                if ($best === null || strlen($binary) < strlen($best)) {
                    $best = $binary;
                }
            }

            return $best;
        } catch (Throwable $e) {
            Log::warning('Delivery trip image compression failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);

            return null;
        }
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function applyExifOrientation($image, string $path, string $mime)
    {
        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/pjpeg'], true) || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if (! is_array($exif) || empty($exif['Orientation'])) {
            return $image;
        }

        return match ((int) $exif['Orientation']) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function resizeResource($image, int $maxEdge = self::MAX_EDGE)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            return $image;
        }

        $maxEdge = max(256, $maxEdge);
        $maxSide = max($width, $height);
        if ($maxSide <= $maxEdge) {
            return $image;
        }

        $scale = $maxEdge / $maxSide;
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        if ($canvas === false) {
            return $image;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    private static function outputMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private static function outputExtension(UploadedFile $file): string
    {
        return match (self::outputMime(strtolower((string) $file->getMimeType()))) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private static function compressBinaryFromPath(
        string $path,
        string $mime,
        int $originalSize,
        int $maxEdge = self::MAX_EDGE,
        int $jpegQuality = self::JPEG_QUALITY,
        bool $forceJpeg = false,
    ): ?string {
        $source = match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($source === false) {
            return null;
        }

        $source = self::applyExifOrientation($source, $path, $mime);
        $resized = self::resizeResource($source, $maxEdge);
        imagedestroy($source);

        $outputMime = $forceJpeg ? 'image/jpeg' : self::outputMime($mime);

        ob_start();
        $ok = match ($outputMime) {
            'image/jpeg' => imagejpeg($resized, null, max(40, min(95, $jpegQuality))),
            'image/png' => imagepng($resized, null, self::PNG_COMPRESSION),
            'image/webp' => function_exists('imagewebp') ? imagewebp($resized, null, self::WEBP_QUALITY) : false,
            default => false,
        };
        imagedestroy($resized);
        $data = ob_get_clean();

        if (! $ok || ! is_string($data) || $data === '') {
            return null;
        }

        if ($forceJpeg) {
            return $data;
        }

        return strlen($data) < $originalSize ? $data : null;
    }
}
