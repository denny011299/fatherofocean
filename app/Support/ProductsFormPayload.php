<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Merge a JSON products snapshot from the browser into the request.
 * Avoids PHP max_input_vars truncating large product forms.
 */
final class ProductsFormPayload
{
    public static function mergeIntoRequest(Request $request): void
    {
        $raw = $request->input('products_payload');
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        $normalized = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        if ($normalized !== []) {
            $request->merge(['products' => $normalized]);
        }
    }

    public static function assertCompleteForExistingBarang(Request $request, int $existingProductCount): void
    {
        if ($existingProductCount <= 0) {
            return;
        }

        $raw = $request->input('products_payload');
        if (! is_string($raw) || trim($raw) === '') {
            throw ValidationException::withMessages([
                'products_payload' => [__('barang.products_payload_required')],
            ]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'products_payload' => [__('barang.products_payload_invalid')],
            ]);
        }

        $products = $request->input('products');
        if (! is_array($products) || $products === []) {
            throw ValidationException::withMessages([
                'products_payload' => [__('barang.products_payload_invalid')],
            ]);
        }
    }
}
