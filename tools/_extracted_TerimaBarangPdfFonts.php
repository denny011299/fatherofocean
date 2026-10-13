<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use FontLib\Font;

final class TerimaBarangPdfFonts
{
    public const FAMILY = 'notosanssc';

    private const FONT_BASENAME = 'NotoSansSC-Regular';

    private const MIN_FONT_BYTES = 500_000;

    /**
     * Prefer storage TTF (full font). Public "NotoSansSC-Regular.ttf" is often an OTF misnamed as .ttf.
     *
     * @var list<string>
     */
    private const FONT_CANDIDATES = [
        'storage/fonts/NotoSansSC-Regular.ttf',
        'storage/fonts/NotoSansSC-Regular.otf',
        'public/fonts/NotoSansSC-Regular.otf',
        'public/fonts/NotoSansSC-Regular.ttf',
    ];

    private const SUBSET_DOWNLOAD_URL = 'https://github.com/googlefonts/noto-cjk/raw/refs/heads/main/Sans/SubsetOTF/SC/NotoSansSC-Regular.otf';

    public static function absolutePath(): ?string
    {
        foreach (self::FONT_CANDIDATES as $relative) {
            $path = base_path($relative);
            if (self::isUsableFontFile($path)) {
                return str_replace('\\', '/', $path);
            }
        }

        return self::downloadSubsetFont();
    }

    /**
     * Path Dompdf loads from storage/fonts (always a real TTF copy).
     */
    public static function dompdfFontPath(?string $fontDir = null): ?string
    {
        $fontDir = $fontDir ?? storage_path('fonts');
        $target = $fontDir.'/'.self::FONT_BASENAME.'.ttf';

        return is_file($target) && self::isTrueTypeFont($target) ? str_replace('\\', '/', $target) : null;
    }

    /**
     * Prepare DomPDF font directory (installed-fonts.json + .ttf + .ufm).
     */
    public static function bootstrap(?string $fontDir = null): bool
    {
        $fontDir = $fontDir ?? storage_path('fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
        }

        $sourcePath = self::absolutePath();
        if ($sourcePath === null) {
            return false;
        }

        $targetTtf = $fontDir.'/'.self::FONT_BASENAME.'.ttf';
        if (! is_file($targetTtf) || ! self::isTrueTypeFont($targetTtf) || filemtime($sourcePath) > filemtime($targetTtf)) {
            if (self::isTrueTypeFont($sourcePath)) {
                if (! copy($sourcePath, $targetTtf)) {
                    return false;
                }
            } elseif (! self::installOpenTypeAsDompdfFont($sourcePath, $targetTtf)) {
                return false;
            }
        }

        if (! self::isTrueTypeFont($targetTtf)) {
            return false;
        }

        $targetUfm = $fontDir.'/'.self::FONT_BASENAME.'.ufm';
        if (self::needsUfmRebuild($targetTtf, $targetUfm)) {
            self::purgeCachedMetrics($fontDir);
            self::generateUfm($targetTtf, $targetUfm);
        }

        if (! is_file($targetUfm)) {
            return false;
        }

        self::writeInstalledFontsJson($fontDir);

        return true;
    }

    public static function register(Dompdf $dompdf): bool
    {
        $fontDir = $dompdf->getOptions()->getFontDir() ?: storage_path('fonts');

        return self::bootstrap($fontDir);
    }

    public static function preloadMetrics(string $fontPath): void
    {
        self::bootstrap(storage_path('fonts'));
    }

    public static function isUsableFontFile(?string $path): bool
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return false;
        }

        if (filesize($path) < self::MIN_FONT_BYTES) {
            return false;
        }

        return self::isTrueTypeFont($path) || self::isOpenTypeFont($path);
    }

    private static function isTrueTypeFont(string $path): bool
    {
        $header = @file_get_contents($path, false, null, 0, 4);

        return $header === "\0\x01\0\0";
    }

    private static function isOpenTypeFont(string $path): bool
    {
        $header = @file_get_contents($path, false, null, 0, 4);

        return $header === 'OTTO' || $header === 'true' || $header === 'typ1';
    }

    private static function needsUfmRebuild(string $ttfPath, string $ufmPath): bool
    {
        if (! is_file($ufmPath)) {
            return true;
        }

        return filemtime($ttfPath) > filemtime($ufmPath);
    }

    /**
     * @return list<string>
     */
    public static function allCjkLabelCharacters(): array
    {
        return [
            '客户', '入库日期', '供应商', '运单', '流水号', '收货照片', '业务',
            '商品', '箱数', '体积', '重量', '备注', '价格', '总计', '发票及装箱单',
        ];
    }

    public static function cjkPreloadString(): string
    {
        return implode('', self::allCjkLabelCharacters());
    }

    private static function installOpenTypeAsDompdfFont(string $otfPath, string $targetTtf): bool
    {
        $fontDir = dirname($targetTtf);
        $targetOtf = $fontDir.'/'.self::FONT_BASENAME.'.otf';

        if (! is_file($targetOtf) || filemtime($otfPath) > filemtime($targetOtf)) {
            if (! copy($otfPath, $targetOtf)) {
                return false;
            }
        }

        $targetUfm = $fontDir.'/'.self::FONT_BASENAME.'.ufm';
        if (self::needsUfmRebuild($targetOtf, $targetUfm)) {
            self::purgeCachedMetrics($fontDir);
            self::generateUfm($targetOtf, $targetUfm);
        }

        if (! is_file($targetUfm)) {
            return false;
        }

        return copy($targetOtf, $targetTtf);
    }

    private static function purgeCachedMetrics(string $fontDir): void
    {
        foreach (glob($fontDir.'/'.self::FONT_BASENAME.'*.ufm') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function generateUfm(string $fontPath, string $ufmPath): void
    {
        $font = Font::load($fontPath);
        if ($font === null) {
            return;
        }

        $font->parse();
        $font->saveAdobeFontMetrics($ufmPath);
        $font->close();
    }

    private static function writeInstalledFontsJson(string $fontDir): void
    {
        $installedPath = $fontDir.'/installed-fonts.json';
        $families = [];

        if (is_file($installedPath)) {
            $decoded = json_decode((string) file_get_contents($installedPath), true);
            if (is_array($decoded)) {
                $families = $decoded;
            }
        }

        if ($families === []) {
            $dist = base_path('vendor/dompdf/dompdf/lib/fonts/installed-fonts.dist.json');
            if (is_file($dist)) {
                $decoded = json_decode((string) file_get_contents($dist), true);
                if (is_array($decoded)) {
                    $families = $decoded;
                }
            }
        }

        $families[self::FAMILY] = [
            'normal' => self::FONT_BASENAME,
            'bold' => self::FONT_BASENAME,
            'italic' => self::FONT_BASENAME,
            'bold_italic' => self::FONT_BASENAME,
        ];

        file_put_contents(
            $installedPath,
            json_encode($families, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private static function downloadSubsetFont(): ?string
    {
        $targetDir = public_path('fonts');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = $targetDir.'/'.self::FONT_BASENAME.'.otf';
        if (is_file($target) && self::isUsableFontFile($target)) {
            return str_replace('\\', '/', $target);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'Pelayaran-PdfFontInstaller/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $bytes = @file_get_contents(self::SUBSET_DOWNLOAD_URL, false, $context);
        if ($bytes === false || strlen($bytes) < self::MIN_FONT_BYTES) {
            return null;
        }

        file_put_contents($target, $bytes);

        return str_replace('\\', '/', $target);
    }
}
