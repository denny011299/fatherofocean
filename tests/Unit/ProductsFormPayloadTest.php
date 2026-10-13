<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductsFormPayload;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ProductsFormPayloadTest extends TestCase
{
    public function test_merge_replaces_truncated_products_array(): void
    {
        $payload = json_encode([
            ['id' => 1, 'product_name' => 'Box 1', 'ctns' => 5, 'is_sub_item' => 0],
            ['id' => 2, 'product_name' => '', 'ctns' => 1, 'is_sub_item' => 0],
            ['is_sub_item' => 1, 'product_name' => 'Sub A', 'pcs_per_box' => 10],
            ['id' => 3, 'product_name' => 'Box 3', 'ctns' => 2, 'is_sub_item' => 0],
            ['id' => 4, 'product_name' => '', 'ctns' => 3, 'is_sub_item' => 0],
            ['is_sub_item' => 1, 'product_name' => 'Sub B', 'pcs_per_box' => 5],
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/barang/1', 'PUT', [
            'products' => [
                ['id' => 1, 'product_name' => 'Box 1', 'ctns' => 5],
                ['id' => 2, 'product_name' => '', 'ctns' => 1],
            ],
            'products_payload' => $payload,
        ]);

        ProductsFormPayload::mergeIntoRequest($request);

        $products = $request->input('products');
        $this->assertIsArray($products);
        $this->assertCount(6, $products);
        $this->assertSame('Sub B', $products[5]['product_name']);
        $this->assertSame(1, $products[5]['is_sub_item']);
    }

    public function test_merge_ignores_invalid_payload_when_no_existing_products(): void
    {
        $request = Request::create('/barang/1', 'PUT', [
            'products' => [['product_name' => 'Keep']],
            'products_payload' => 'not-json',
        ]);

        ProductsFormPayload::mergeIntoRequest($request);

        $this->assertSame('Keep', $request->input('products.0.product_name'));
    }

    public function test_assert_complete_rejects_missing_payload_when_products_exist(): void
    {
        $request = Request::create('/barang/1', 'PUT', [
            'products' => [['id' => 1, 'product_name' => 'Box 1']],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        ProductsFormPayload::assertCompleteForExistingBarang($request, 5);
    }

    public function test_assert_complete_accepts_valid_payload(): void
    {
        $payload = json_encode([
            ['id' => 1, 'product_name' => 'Box 1', 'ctns' => 5],
            ['id' => 2, 'product_name' => 'Box 2', 'ctns' => 1],
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/barang/1', 'PUT', [
            'products_payload' => $payload,
        ]);

        ProductsFormPayload::mergeIntoRequest($request);
        ProductsFormPayload::assertCompleteForExistingBarang($request, 2);

        $this->assertCount(2, $request->input('products'));
    }
}
