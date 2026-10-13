<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BarangProductStagedPhotoTest extends TestCase
{
    public function test_bulk_insert_promotes_staged_product_photo_path(): void
    {
        Storage::fake('public');

        $stagedPath = 'barang-product-staging/user-1/products/sample.jpg';
        Storage::disk('public')->put($stagedPath, 'fake-image');

        $barang = Barang::query()->create([
            'no_index' => 'IDX-PHOTO-' . uniqid(),
            'marking' => 'MARK-PHOTO',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $request = Request::create('/', 'POST', [
            'products' => [
                [
                    'product_name' => 'Widget',
                    'ctns' => 1,
                    'pcs_per_box' => 10,
                    'photo_path' => $stagedPath,
                ],
            ],
        ]);

        $barang->syncProductsFromRequest($request);

        $product = $barang->products()->first();
        $this->assertNotNull($product);
        $this->assertIsString($product->photo_path);
        $this->assertStringStartsWith('barang-products/', $product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);
    }
}
