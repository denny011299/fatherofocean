<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\BarangAdditionalPrice;
use App\Models\BarangProduct;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BarangCrudService
{
    /** @var list<string> */
    private const PRODUCT_LOGISTICS_FIELDS = ['ctns', 'length_cm', 'width_cm', 'height_cm'];

    /** @param array<string, mixed> $data */
    public function store(array $data, Request $request): Barang
    {
        return DB::transaction(function () use ($data, $request): Barang {
            $barang = Barang::create($data);
            $this->persistRelations($request, $barang);

            return $barang;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(Barang $barang, array $data, Request $request): Barang
    {
        DB::transaction(function () use ($data, $request, $barang): void {
            $barang->update($data);
            $this->persistRelations($request, $barang);
        }, 3);

        return $barang->refresh();
    }

    /** @return array{invoice_ids: list<int>, snapshot: array<string, mixed>} */
    public function destroy(Barang $barang): array
    {
        $invoiceIds = $barang->invoiceItems()->pluck('invoice_id')->unique()->values()->all();
        $snapshot = $barang->toArray();

        DB::transaction(function () use ($barang): void {
            $barang->invoiceItems()->delete();
            $barang->containers()->detach();
            $barang->delete();
        }, 3);

        return [
            'invoice_ids' => array_map(static fn ($id) => (int) $id, $invoiceIds),
            'snapshot' => $snapshot,
        ];
    }

    private function persistRelations(Request $request, Barang $barang): void
    {
        $this->enforceProductLogisticsPermissions($request, $barang);
        $products = $this->syncBarangProducts($request, $barang);
        $this->syncBarangAdditionalPrices($request, $barang);
        $barang->setRelation('products', $products);
        $barang->recalculateTotals();
    }

    private function enforceProductLogisticsPermissions(Request $request, Barang $barang): void
    {
        if (! $request->has('products')) {
            return;
        }

        $rows = $request->input('products', []);
        if (! is_array($rows)) {
            return;
        }

        $existingById = $barang->exists
            ? $barang->products()->get()->keyBy('id')
            : collect();

        $blocked = false;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (self::PRODUCT_LOGISTICS_FIELDS as $field) {
                if (! empty($row['id']) && $existingById->has((int) $row['id'])) {
                    /** @var BarangProduct $product */
                    $product = $existingById->get((int) $row['id']);
                    if ($this->productLogisticsValueChanged($row, $product, $field)) {
                        $blocked = true;
                    }
                    $rows[$index][$field] = $product->{$field};
                } elseif ($this->productLogisticsIncomingNotEmpty($row, $field)) {
                    $blocked = true;
                    $rows[$index][$field] = 0;
                }
            }
        }

        $request->merge(['products' => $rows]);

        if ($blocked) {
            ActivityLog::record('Barang product logistics change blocked', $barang);
        }
    }

    /** @param array<string, mixed> $row */
    private function productLogisticsIncomingNotEmpty(array $row, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) > 0;
        }

        return (float) ($row[$field] ?? 0) > 0;
    }

    /** @param array<string, mixed> $row */
    private function productLogisticsValueChanged(array $row, BarangProduct $product, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) !== (int) $product->ctns;
        }

        return abs((float) ($row[$field] ?? 0) - (float) $product->{$field}) > 0.0001;
    }

    private function syncBarangAdditionalPrices(Request $request, Barang $barang): void
    {
        if (! $request->has('additional_prices')) {
            return;
        }

        $rows = $request->input('additional_prices', []);
        if (! is_array($rows)) {
            return;
        }

        $hasExistingIds = false;
        foreach ($rows as $row) {
            if (is_array($row) && ! empty($row['id'])) {
                $hasExistingIds = true;
                break;
            }
        }

        $existing = $hasExistingIds
            ? $barang->additionalPrices()->get()->keyBy('id')
            : collect();

        $keepIds = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $price = (float) ($row['price'] ?? 0);

            if ($name === '' && $price <= 0) {
                continue;
            }

            $payload = [
                'sort_order' => $sort++,
                'name' => $name !== '' ? $name : '-',
                'price' => $price,
            ];

            $record = null;
            if (! empty($row['id'])) {
                $record = $existing->get((int) $row['id']);
            }

            if ($record) {
                if ($this->additionalPriceChanged($record, $payload)) {
                    $record->update($payload);
                }
                $keepIds[] = $record->id;
            } else {
                $created = $barang->additionalPrices()->create($payload);
                $keepIds[] = $created->id;
            }
        }

        if ($existing->isNotEmpty()) {
            $barang->additionalPrices()->whereNotIn('id', $keepIds)->delete();
        }
    }

    /**
     * @return Collection<int, BarangProduct>
     */
    private function syncBarangProducts(Request $request, Barang $barang): Collection
    {
        if (! $request->has('products')) {
            return collect();
        }

        $rows = $request->input('products', []);
        if (! is_array($rows)) {
            return collect();
        }

        $hasExistingIds = false;
        $hasUploads = false;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! empty($row['id'])) {
                $hasExistingIds = true;
            }
            if ($request->hasFile("products.{$index}.photo")) {
                $hasUploads = true;
            }
        }

        if (! $hasExistingIds && ! $hasUploads) {
            $synced = $this->bulkInsertBarangProducts($request, $barang, $rows);
            if ($synced !== null) {
                return $synced;
            }
        }

        return $this->syncBarangProductsIteratively($request, $barang, $rows, $hasExistingIds);
    }

    /**
     * Fast path for create: one INSERT for all new product rows (no photos).
     *
     * @param  array<int, mixed>  $rows
     * @return Collection<int, BarangProduct>|null
     */
    private function bulkInsertBarangProducts(Request $request, Barang $barang, array $rows): ?Collection
    {
        $now = now();
        $insertRows = [];
        $sort = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->isEmptyProductRow($row)) {
                continue;
            }

            $ctns = (int) ($row['ctns'] ?? 0);
            $length = (float) ($row['length_cm'] ?? 0);
            $width = (float) ($row['width_cm'] ?? 0);
            $height = (float) ($row['height_cm'] ?? 0);
            $pcs = (int) ($row['pcs_per_box'] ?? 0);
            $unitPrice = (float) ($row['price_per_unit_rmb'] ?? 0);
            $totalPrice = (float) ($row['total_price_rmb'] ?? 0);

            if ($unitPrice > 0 && $pcs > 0 && $ctns > 0) {
                $totalPrice = $unitPrice * $pcs * $ctns;
            }

            $insertRows[] = [
                'barang_id' => $barang->id,
                'sort_order' => $sort++,
                'ctns' => $ctns,
                'product_name' => $row['product_name'] ?? null,
                'material' => $row['material'] ?? null,
                'pcs_per_box' => $pcs,
                'gross_weight_kg' => $row['gross_weight_kg'] ?? 0,
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
                'volume_cbm' => ($length * $width * $height * max(0, $ctns)) / 1_000_000,
                'price_per_unit_rmb' => $unitPrice,
                'total_price_rmb' => $totalPrice,
                'photo_path' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insertRows === []) {
            return collect();
        }

        BarangProduct::query()->insert($insertRows);

        return $barang->products()->orderBy('sort_order')->get();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return Collection<int, BarangProduct>
     */
    private function syncBarangProductsIteratively(Request $request, Barang $barang, array $rows, bool $hasExistingIds): Collection
    {
        $existing = $hasExistingIds
            ? $barang->products()->get()->keyBy('id')
            : collect();

        $keepIds = [];
        $sort = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->isEmptyProductRow($row) && ! $request->hasFile("products.{$index}.photo")) {
                continue;
            }

            $product = null;
            if (! empty($row['id'])) {
                $product = $existing->get((int) $row['id']);
            }

            $payload = [
                'sort_order' => $sort++,
                'ctns' => (int) ($row['ctns'] ?? 0),
                'product_name' => $row['product_name'] ?? null,
                'material' => $row['material'] ?? null,
                'pcs_per_box' => (int) ($row['pcs_per_box'] ?? 0),
                'gross_weight_kg' => $row['gross_weight_kg'] ?? 0,
                'length_cm' => $row['length_cm'] ?? 0,
                'width_cm' => $row['width_cm'] ?? 0,
                'height_cm' => $row['height_cm'] ?? 0,
                'price_per_unit_rmb' => $row['price_per_unit_rmb'] ?? 0,
                'total_price_rmb' => $row['total_price_rmb'] ?? 0,
            ];

            $file = $request->file("products.{$index}.photo");
            if ($file) {
                if ($product?->photo_path) {
                    Storage::disk('public')->delete($product->photo_path);
                }
                $payload['photo_path'] = UploadedImageCompressor::store($file, 'barang-products', 'public');
            } elseif ($request->boolean("products.{$index}.remove_photo") && $product?->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
                $payload['photo_path'] = null;
            }

            if ($product) {
                if ($this->productChanged($product, $payload)) {
                    $product->update($payload);
                }
                $keepIds[] = $product->id;
            } else {
                $created = $barang->products()->create($payload);
                $keepIds[] = $created->id;
            }
        }

        if ($existing->isNotEmpty()) {
            $barang->products()->whereNotIn('id', $keepIds)->delete();
        }

        return $barang->products()->orderBy('sort_order')->get();
    }

    /** @param array<string, mixed> $payload */
    private function additionalPriceChanged(BarangAdditionalPrice $record, array $payload): bool
    {
        return (int) $record->sort_order !== (int) ($payload['sort_order'] ?? 0)
            || trim((string) $record->name) !== trim((string) ($payload['name'] ?? ''))
            || abs((float) $record->price - (float) ($payload['price'] ?? 0)) > 0.0001;
    }

    /** @param array<string, mixed> $payload */
    private function productChanged(BarangProduct $product, array $payload): bool
    {
        if (array_key_exists('photo_path', $payload)) {
            return true;
        }

        $stringFields = ['product_name', 'material'];
        foreach ($stringFields as $field) {
            if (trim((string) $product->{$field}) !== trim((string) ($payload[$field] ?? ''))) {
                return true;
            }
        }

        $intFields = ['sort_order', 'ctns', 'pcs_per_box'];
        foreach ($intFields as $field) {
            if ((int) $product->{$field} !== (int) ($payload[$field] ?? 0)) {
                return true;
            }
        }

        $decimalFields = ['gross_weight_kg', 'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb'];
        foreach ($decimalFields as $field) {
            if (abs((float) $product->{$field} - (float) ($payload[$field] ?? 0)) > 0.0001) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function isEmptyProductRow(array $row): bool
    {
        $textFields = ['product_name', 'material'];
        foreach ($textFields as $field) {
            if (! empty(trim((string) ($row[$field] ?? '')))) {
                return false;
            }
        }

        $numericFields = [
            'ctns', 'pcs_per_box', 'gross_weight_kg',
            'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb',
        ];

        foreach ($numericFields as $field) {
            if ((float) ($row[$field] ?? 0) > 0) {
                return false;
            }
        }

        return empty($row['id']);
    }
}
