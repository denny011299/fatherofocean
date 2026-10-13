<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BarangProduct;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BarangProductPhotoUrlTest extends TestCase
{
    public function test_photo_url_is_relative_storage_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('barang-products/sample.jpg', 'fake');

        $product = new BarangProduct(['photo_path' => 'barang-products/sample.jpg']);

        $this->assertSame(asset('storage/barang-products/sample.jpg'), $product->photoUrl());
    }

    public function test_photo_url_is_null_when_path_missing(): void
    {
        $product = new BarangProduct(['photo_path' => null]);

        $this->assertNull($product->photoUrl());
    }

    public function test_photo_url_is_null_when_file_missing_on_disk(): void
    {
        Storage::fake('public');

        $product = new BarangProduct(['photo_path' => 'barang-products/missing.jpg']);

        $this->assertNull($product->photoUrl());
    }

    public function test_photo_url_strips_storage_prefix_from_path(): void
    {
        $product = new BarangProduct(['photo_path' => 'storage/barang-products/sample.jpg']);

        $this->assertSame('barang-products/sample.jpg', $product->normalizedPhotoPath());
    }

    public function test_photo_url_returns_absolute_url_when_path_is_http(): void
    {
        $product = new BarangProduct(['photo_path' => 'https://cdn.example/photo.jpg']);

        $this->assertSame('https://cdn.example/photo.jpg', $product->photoUrl());
    }
}
