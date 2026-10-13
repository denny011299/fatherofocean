<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UploadedImageCompressor;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class UploadedImageCompressorTest extends TestCase
{
    public function test_compresses_large_jpeg(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }

        $path = sys_get_temp_dir().'/compress-test-'.uniqid('', true).'.jpg';
        $image = imagecreatetruecolor(4000, 3000);
        imagejpeg($image, $path, 100);
        imagedestroy($image);

        $file = new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);

        $this->assertTrue(UploadedImageCompressor::isCompressible($file));
        $this->assertGreaterThan(UploadedImageCompressor::SKIP_BELOW_BYTES, $file->getSize());

        $compressed = UploadedImageCompressor::compressedContents($file);
        $this->assertNotNull($compressed);
        $this->assertLessThan($file->getSize(), strlen($compressed));

        @unlink($path);
    }

    public function test_skips_pdf(): void
    {
        $path = sys_get_temp_dir().'/proof-'.uniqid('', true).'.pdf';
        file_put_contents($path, '%PDF-1.4 fake');
        $file = new UploadedFile($path, 'proof.pdf', 'application/pdf', null, true);

        $this->assertFalse(UploadedImageCompressor::isCompressible($file));

        @unlink($path);
    }
}
