<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use FontLib\Font;

final class TerimaBarangPdfFonts
{
    public const FAMILY = 'notosanssc';

    private const FONT_BASENAME = 'NotoSansSC-Regular';

    /**
     * @var list<string>
     */
    private const FONT_CANDIDATES = [
        'public/fonts/NotoSansSC-Regular.otf',
        'public/fonts/NotoSansSC-Regular.ttf',
        'storage/fonts/NotoSansSC-Regular.otf',
        'storage/fonts/NotoSansSC-Regular.ttf',
    ];

    private const SUBSET_DOWNLOAD_URL = 'https://github.com/googlefonts/noto-cjk/raw/refs/heads/main/Sans/SubsetOTF/SC/NotoSansSC-Regular.otf';

    public static function absolutePath(): ?string
    {
        foreach (self::FONT_CANDIDATES as $relative) {
            $path = base_path($relative);
            if (is_file($path) && filesize($path) > 500_000) {
                return str_replace('\\', '/', $path);
            }
        }

        return self::downloadSubsetFont();
    }

    public static function register(Dompdf $dompdf): bool
    {
        $path = self::absolutePath();
        if ($path === null) {
            return false;
        }

        self::ensureDompdfMetrics($dompdf->getFontMetrics(), $path);

        return $dompdf->getFontMetrics()->registerFont([
            'family' => self::FAMILY,
            'style' => 'normal',
            'weight' => 'normal',
        ], self::fileUri($path));
    }

    private static function ensureDompdfMetrics(FontMetrics $fontMetrics, string $fontPath): void
    {
        $fontDir = storage_path('fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
        }

        $options = $fontMetrics->getOptions();
        $options->setFontDir($fontDir);
        $options->setFontCache($fontDir);
        $options->set('isRemoteEnabled', true);

        $installed = $fontDir.'/installed-fonts.json';
        if (! is_file($installed)) {
            $dist = base_path('vendor/dompdf/dompdf/lib/fonts/installed-fonts.dist.json');
            if (is_file($dist)) {
                copy($dist, $installed);
            }
        }

        $target = $fontDir.'/'.self::FONT_BASENAME.'.otf';
        if (! is_file($target) || filesize($target) < 500_000) {
            copy($fontPath, $target);
        }
    }

    private static function downloadSubsetFont(): ?string
    {
        $targetDir = public_path('fonts');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = $targetDir.'/'.self::FONT_BASENAME.'.otf';
        if (is_file($target) && filesize($target) > 500_000) {
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
        if ($bytes === false || strlen($bytes) < 500_000) {
            return null;
        }

        file_put_contents($target, $bytes);

        return str_replace('\\', '/', $target);
    }

    private static function fileUri(string $absolutePath): string
    {
        $path = str_replace('\\', '/', $absolutePath);

        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            return 'file:///'.$path;
        }

        return 'file://'.$path;
    }

    public static function preloadMetrics(string $fontPath): void
    {
        $fontDir = storage_path('fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
        }

        $hash = md5($fontPath);
        $ufm = $fontDir.'/'.self::FONT_BASENAME.'_normal_'.$hash.'.ufm';
        if (is_file($ufm)) {
            return;
        }

        $font = Font::load($fontPath);
        if ($font === null) {
            return;
        }

        $font->parse();
        $font->saveAdobeFontMetrics($ufm);
        $font->close();
    }
}
