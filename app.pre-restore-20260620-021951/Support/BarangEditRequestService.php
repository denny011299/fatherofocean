<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\BarangEditRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class BarangEditRequestService
{
    /** @var list<string> */
    private const APPROVAL_PRODUCT_FIELDS = ['length_cm', 'width_cm', 'height_cm'];

    /** @var list<string> */
    private const APPROVAL_DIMENSION_FIELDS = ['panjang', 'lebar', 'tinggi'];

    /** @var list<string> */
    private const PRODUCT_PHOTO_FIELDS = [
        'photo_path',
        'length_photo_path',
        'width_photo_path',
        'height_photo_path',
    ];

    /**
     * Pengajuan is required only when L/W/H dimensions change (products or barang_dimensions).
     *
     * @param  array{data: array<string, mixed>, old: array<string, mixed>}  $prepared
     */
    public function requiresApproval(Barang $barang, Request $request, array $prepared): bool
    {
        $snapshot = $this->buildSnapshot($barang);
        $data = $prepared['data'];
        $rawProducts = $data['products'] ?? $request->input('products', []);

        if (! is_array($rawProducts)) {
            $rawProducts = [];
        }

        $proposedProducts = $this->buildProposedProducts($rawProducts, $snapshot['products'] ?? []);

        if ($this->hasProductLwhChanges($snapshot['products'] ?? [], $proposedProducts)) {
            return true;
        }

        return $this->hasBulkDimensionLwhChanges($barang, $request);
    }

    /**
     * @param  array<string, mixed>  $barangData
     * @param  list<array<string, mixed>>|null  $validatedProducts
     */
    public function createFromUpdate(
        Barang $barang,
        Request $request,
        array $barangData,
        ?array $validatedProducts = null,
    ): BarangEditRequest {
        if ($barang->pendingEditRequest()->exists()) {
            throw new \RuntimeException(__('barang.edit_request_pending_exists'));
        }

        $stagingToken = Str::uuid()->toString();
        $stagedFiles = $this->stageUploadedFiles($request, $stagingToken);
        $snapshot = $this->buildSnapshot($barang);
        $rawProducts = is_array($validatedProducts)
            ? $validatedProducts
            : $request->input('products', []);

        if (! is_array($rawProducts)) {
            $rawProducts = [];
        }

        $proposedPayload = [
            'barang' => $barangData,
            'products' => $this->buildProposedProducts($rawProducts, $snapshot['products'] ?? []),
            'additional_prices' => $request->input('additional_prices', []),
            'flags' => [
                'remove_invoice_file' => $request->boolean('remove_invoice_file'),
                'remove_good_receipt_file' => $request->boolean('remove_good_receipt_file'),
                'remove_tracking_receipt_file' => $request->boolean('remove_tracking_receipt_file'),
            ],
        ];

        return DB::transaction(function () use ($barang, $proposedPayload, $stagedFiles, $snapshot): BarangEditRequest {
            $editRequest = BarangEditRequest::query()->create([
                'barang_id' => $barang->id,
                'submitted_by' => auth()->id(),
                'status' => BarangEditRequest::STATUS_PENDING,
                'current_snapshot' => $snapshot,
                'proposed_payload' => $proposedPayload,
                'staged_files' => $stagedFiles,
                'submitted_at' => now(),
            ]);

            ActivityLog::record(
                'Barang edit submitted',
                $barang,
                $editRequest->current_snapshot,
                $editRequest->logContext(),
            );

            return $editRequest;
        });
    }

    public function apply(BarangEditRequest $editRequest, callable $executeUpdate): Barang
    {
        if (! $editRequest->isPending()) {
            throw new \RuntimeException(__('barang.edit_request_already_processed'));
        }

        $barang = $editRequest->barang;
        $request = $this->rebuildRequest($editRequest);

        return DB::transaction(function () use ($editRequest, $barang, $request, $executeUpdate): Barang {
            $old = $barang->only([
                'id', 'no_index', 'marking', 'status', 'user_id', 'gudang_china_id',
                'total_cbm', 'total_gross_weight', 'price_per_cbm', 'weight_price',
                'approval_status', 'tanggal_masuk',
            ]);

            $updated = $executeUpdate($barang, $request);

            $editRequest->update([
                'status' => BarangEditRequest::STATUS_APPROVED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $this->deleteStagedFiles($editRequest);

            ActivityLog::record(
                'Barang edit approved',
                $updated,
                $old,
                $editRequest->logContext(),
            );

            return $updated;
        });
    }

    public function reject(BarangEditRequest $editRequest, string $rejectionNote): void
    {
        if (! $editRequest->isPending()) {
            throw new \RuntimeException(__('barang.edit_request_already_processed'));
        }

        DB::transaction(function () use ($editRequest, $rejectionNote): void {
            $editRequest->update([
                'status' => BarangEditRequest::STATUS_REJECTED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'rejection_note' => $rejectionNote,
            ]);

            $this->deleteStagedFiles($editRequest);

            ActivityLog::record(
                'Barang edit rejected',
                $editRequest->barang,
                $editRequest->current_snapshot,
                $editRequest->logContext() + ['rejection_note' => $rejectionNote],
            );
        });
    }

    /**
     * @return list<array{field: string, label: string, old: string, new: string, group?: string}>
     */
    public function diffRows(BarangEditRequest $editRequest): array
    {
        $productRows = $this->productDiffRows($editRequest);
        $barangRows = $this->barangDiffRows($editRequest, $productRows !== []);

        if ($productRows !== [] && $barangRows !== []) {
            foreach ($barangRows as &$row) {
                $row['group'] = 'barang';
            }
            unset($row);

            return array_merge($productRows, $barangRows);
        }

        return array_merge($productRows, $barangRows);
    }

    /**
     * @param  list<array<string, mixed>>  $snapshotProducts
     * @param  list<array<string, mixed>>  $proposedProducts
     */
    private function hasProductLwhChanges(array $snapshotProducts, array $proposedProducts): bool
    {
        $snapshotById = $this->indexProductsById($snapshotProducts);

        foreach ($proposedProducts as $proposed) {
            if (! is_array($proposed)) {
                continue;
            }

            $id = $this->productIdFromRow($proposed);
            if ($id === null || ! isset($snapshotById[$id])) {
                foreach (self::APPROVAL_PRODUCT_FIELDS as $field) {
                    if ($this->numericValue($proposed[$field] ?? 0) > 0) {
                        return true;
                    }
                }

                continue;
            }

            $snapshot = $snapshotById[$id];
            foreach (self::APPROVAL_PRODUCT_FIELDS as $field) {
                if (! $this->productValuesEqual(
                    $field,
                    $snapshot[$field] ?? null,
                    $proposed[$field] ?? null,
                )) {
                    return true;
                }
            }
        }

        $proposedIds = [];
        foreach ($proposedProducts as $proposed) {
            $id = $this->productIdFromRow(is_array($proposed) ? $proposed : []);
            if ($id !== null) {
                $proposedIds[$id] = true;
            }
        }

        foreach ($snapshotById as $id => $snapshot) {
            if (isset($proposedIds[$id])) {
                continue;
            }

            foreach (self::APPROVAL_PRODUCT_FIELDS as $field) {
                if ($this->numericValue($snapshot[$field] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasBulkDimensionLwhChanges(Barang $barang, Request $request): bool
    {
        if (! $request->has('dimensions')) {
            return false;
        }

        $rows = $request->input('dimensions', []);
        if (! is_array($rows)) {
            return false;
        }

        $barang->loadMissing('dimensions');
        $existingById = $barang->dimensions->keyBy('id');

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $existing = ! empty($row['id']) ? $existingById->get((int) $row['id']) : null;

            foreach (self::APPROVAL_DIMENSION_FIELDS as $field) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }

                $oldVal = $existing !== null ? $existing->{$field} : 0;
                if (! $this->productValuesEqual($field, $oldVal, $row[$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{field: string, label: string, old: string, new: string, group: string}>
     */
    private function productDiffRows(BarangEditRequest $editRequest): array
    {
        $snapshotProducts = $editRequest->current_snapshot['products'] ?? [];
        $proposedProducts = $editRequest->proposed_payload['products'] ?? [];
        $snapshotById = $this->indexProductsById($snapshotProducts);

        $rows = [];
        $proposedIds = [];
        $boxNumber = 0;

        foreach ($proposedProducts as $index => $proposed) {
            if (! is_array($proposed)) {
                continue;
            }

            $isSubItem = $this->isSubItemProductRow($proposed);
            if (! $isSubItem) {
                $boxNumber++;
            }

            $positionLabel = $isSubItem
                ? __('barang.edit_pengajuan_product_sub_item', ['box' => $boxNumber])
                : __('barang.edit_pengajuan_product_box', ['box' => $boxNumber]);

            $id = $this->productIdFromRow($proposed);

            if ($id !== null && isset($snapshotById[$id])) {
                $proposedIds[$id] = true;
                $snapshot = $snapshotById[$id];
                $proposedForDiff = $this->enrichProductForDiff($snapshot, $proposed);

                foreach ($this->productFieldsForDiff($isSubItem) as $field => $label) {
                    $oldVal = $snapshot[$field] ?? null;
                    $newVal = $proposedForDiff[$field] ?? null;

                    if ($this->productValuesEqual($field, $oldVal, $newVal)) {
                        continue;
                    }

                    $rows[] = [
                        'field' => "product.{$id}.{$field}",
                        'label' => $positionLabel . ' · ' . $label,
                        'old' => $this->formatProductDiffValue($field, $oldVal),
                        'new' => $this->formatProductDiffValue($field, $newVal),
                        'group' => 'product',
                    ];
                }

                continue;
            }

            $name = trim((string) ($proposed['product_name'] ?? ''));
            $rows[] = [
                'field' => 'product.new.' . $index,
                'label' => ($isSubItem
                    ? $positionLabel
                    : __('barang.edit_pengajuan_product_new', ['box' => $boxNumber]))
                    . ($name !== '' ? ' · ' . $name : ''),
                'old' => '-',
                'new' => $this->formatNewProductSummary($proposed, $isSubItem),
                'group' => 'product',
            ];
        }

        foreach ($snapshotById as $id => $snapshot) {
            if (isset($proposedIds[$id])) {
                continue;
            }

            $name = trim((string) ($snapshot['product_name'] ?? ''));
            $rows[] = [
                'field' => 'product.removed.' . $id,
                'label' => __('barang.edit_pengajuan_product_removed')
                    . ($name !== '' ? ' · ' . $name : ''),
                'old' => $this->formatRemovedProductSummary($snapshot),
                'new' => '-',
                'group' => 'product',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    private function barangDiffRows(BarangEditRequest $editRequest, bool $hasProductChanges): array
    {
        $snapshot = $editRequest->current_snapshot['barang'] ?? [];
        $proposed = $editRequest->proposed_payload['barang'] ?? [];
        $rows = [];

        $labels = [
            'marking' => __('barang.col_marking'),
            'customer_name' => __('barang.label_customer'),
            'supplier_chinese' => __('barang.label_supplier'),
            'gudang_china_id' => __('barang.col_warehouse'),
            'tanggal_masuk' => __('barang.col_tanggal_masuk'),
            'destination' => __('barang.filter_destination'),
            'packing_id' => __('barang.col_packing'),
            'tracking_number' => __('barang.label_tracking_number'),
            'notes' => __('barang.col_notes'),
            'notes_id' => __('barang.col_notes'),
            'price_per_cbm' => __('barang.label_price_cbm'),
            'weight_price' => __('barang.label_weight_price'),
            'cbm_type' => __('barang.label_item_type'),
            'no_resi' => __('barang.col_resi'),
        ];

        $hiddenWhenProductsChanged = ['price', 'price_per_cbm', 'weight_price', 'tanggal_masuk'];

        foreach ($labels as $field => $label) {
            $oldRaw = $snapshot[$field] ?? null;
            $newRaw = $proposed[$field] ?? $oldRaw;

            if ($hasProductChanges && in_array($field, $hiddenWhenProductsChanged, true)) {
                if ($this->barangValuesEqual($field, $oldRaw, $newRaw)) {
                    continue;
                }
            }

            if ($this->barangValuesEqual($field, $oldRaw, $newRaw)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => $label,
                'old' => $this->formatDiffValue($field, $oldRaw),
                'new' => $this->formatDiffValue($field, $newRaw),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(Barang $barang): array
    {
        $barang->loadMissing([
            'products' => static fn ($q) => $q->orderBy('sort_order'),
            'additionalPrices',
            'gudangChina:id,name',
            'packing:id,name',
        ]);

        return [
            'barang' => $barang->only([
                'id', 'no_index', 'marking', 'customer_name', 'supplier_chinese',
                'gudang_china_id', 'tanggal_masuk', 'destination', 'other', 'packing_id',
                'tracking_number', 'notes', 'notes_id', 'price', 'price_per_cbm',
                'weight_price', 'cbm_type', 'rate', 'no_resi', 'total_cbm', 'total_gross_weight',
            ]),
            'gudang_name' => $barang->gudangChina?->name,
            'packing_name' => $barang->packing?->name,
            'products' => $barang->products->map(static fn ($p) => array_merge($p->only([
                'id', 'product_name', 'material', 'ctns', 'pcs_per_box',
                'gross_weight_kg', 'length_cm', 'width_cm', 'height_cm',
                'volume_cbm', 'price_per_unit_rmb', 'total_price_rmb', 'parent_id',
                'photo_path', 'length_photo_path', 'width_photo_path', 'height_photo_path',
            ]), [
                'is_sub_item' => $p->parent_id !== null,
            ]))->values()->all(),
            'additional_prices' => $barang->additionalPrices->map(static fn ($p) => $p->only([
                'id', 'name', 'price',
            ]))->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposedProducts
     * @param  list<array<string, mixed>>  $snapshotProducts
     * @return list<array<string, mixed>>
     */
    private function buildProposedProducts(array $proposedProducts, array $snapshotProducts): array
    {
        $snapshotById = $this->indexProductsById($snapshotProducts);
        $built = [];

        foreach ($proposedProducts as $proposed) {
            if (! is_array($proposed)) {
                continue;
            }

            $id = $this->productIdFromRow($proposed);
            $base = ($id !== null && isset($snapshotById[$id]))
                ? $snapshotById[$id]
                : [];

            $built[] = $this->enrichProductForDiff($base, $proposed);
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function enrichProductForDiff(array $snapshot, array $proposed): array
    {
        $merged = array_merge($snapshot, $proposed);

        foreach (self::PRODUCT_PHOTO_FIELDS as $photoField) {
            if (
                (empty($merged[$photoField]) || $merged[$photoField] === null)
                && ! empty($snapshot[$photoField])
            ) {
                $merged[$photoField] = $snapshot[$photoField];
            }
        }

        $isSubItem = $this->isSubItemProductRow($merged);

        return $this->applyProductCalculations($merged, $isSubItem);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function indexProductsById(array $products): array
    {
        $indexed = [];

        foreach ($products as $product) {
            $id = $this->productIdFromRow($product);
            if ($id !== null) {
                $indexed[$id] = $product;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function productIdFromRow(array $row): ?int
    {
        if (! isset($row['id']) || $row['id'] === '' || $row['id'] === null) {
            return null;
        }

        return (int) $row['id'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isSubItemProductRow(array $row): bool
    {
        return ! empty($row['parent_id'])
            || filter_var($row['is_sub_item'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function applyProductCalculations(array $product, bool $isSubItem): array
    {
        if ($isSubItem) {
            $pcs = max(0, (int) ($product['pcs_per_box'] ?? 0));
            $unitPrice = RmbFormat::round((float) ($product['price_per_unit_rmb'] ?? 0));
            $product['price_per_unit_rmb'] = $unitPrice;
            $product['volume_cbm'] = 0;
            $product['total_price_rmb'] = ($unitPrice > 0 && $pcs > 0)
                ? RmbFormat::lineTotal($unitPrice, $pcs)
                : (float) ($product['total_price_rmb'] ?? 0);

            return $product;
        }

        $ctns = max(0, (int) ($product['ctns'] ?? 0));
        $length = (float) ($product['length_cm'] ?? 0);
        $width = (float) ($product['width_cm'] ?? 0);
        $height = (float) ($product['height_cm'] ?? 0);

        $product['volume_cbm'] = CbmFormat::truncate(
            ($length * $width * $height * $ctns) / 1_000_000,
        );

        $pcs = max(0, (int) ($product['pcs_per_box'] ?? 0));
        $unitPrice = RmbFormat::round((float) ($product['price_per_unit_rmb'] ?? 0));
        $product['price_per_unit_rmb'] = $unitPrice;

        if ($unitPrice > 0 && $pcs > 0 && $ctns > 0) {
            $product['total_price_rmb'] = RmbFormat::lineTotal($unitPrice, $pcs, $ctns);
        }

        return $product;
    }

    /**
     * @return array<string, string>
     */
    private function productFieldsForDiff(bool $isSubItem): array
    {
        if ($isSubItem) {
            return [
                'product_name' => __('barang.prod_name'),
                'material' => __('barang.prod_material'),
                'pcs_per_box' => __('barang.prod_sub_qty'),
                'price_per_unit_rmb' => __('barang.prod_unit_price'),
                'total_price_rmb' => __('barang.prod_total_price'),
            ];
        }

        return [
            'product_name' => __('barang.prod_name'),
            'ctns' => __('barang.prod_ctns'),
            'material' => __('barang.prod_material'),
            'pcs_per_box' => __('barang.prod_pcs'),
            'gross_weight_kg' => __('barang.prod_gross'),
            'length_cm' => __('barang.prod_length'),
            'width_cm' => __('barang.prod_width'),
            'height_cm' => __('barang.prod_height'),
            'volume_cbm' => __('barang.prod_cbm'),
            'price_per_unit_rmb' => __('barang.prod_unit_price'),
            'total_price_rmb' => __('barang.prod_total_price'),
        ];
    }

    private function productValuesEqual(string $field, mixed $old, mixed $new): bool
    {
        if (in_array($field, ['ctns', 'pcs_per_box'], true)) {
            return (int) ($old ?? 0) === (int) ($new ?? 0);
        }

        if (in_array($field, ['price_per_unit_rmb', 'total_price_rmb', 'gross_weight_kg', 'length_cm', 'width_cm', 'height_cm', 'volume_cbm', 'panjang', 'lebar', 'tinggi'], true)) {
            return abs($this->numericValue($old) - $this->numericValue($new)) < 0.0005;
        }

        return (string) ($old ?? '') === (string) ($new ?? '');
    }

    private function barangValuesEqual(string $field, mixed $old, mixed $new): bool
    {
        if ($field === 'tanggal_masuk') {
            return $this->normalizeDateValue($old) === $this->normalizeDateValue($new);
        }

        if ($field === 'no_resi') {
            return json_encode($old) === json_encode($new);
        }

        if (in_array($field, ['price_per_cbm', 'weight_price', 'price'], true)) {
            return abs($this->numericValue($old) - $this->numericValue($new)) < 0.0005;
        }

        return (string) ($old ?? '') === (string) ($new ?? '');
    }

    private function numericValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function formatNewProductSummary(array $proposed, bool $isSubItem): string
    {
        $parts = [];
        $name = trim((string) ($proposed['product_name'] ?? ''));
        if ($name !== '') {
            $parts[] = $name;
        }

        if (! $isSubItem && isset($proposed['ctns'])) {
            $parts[] = __('barang.prod_ctns') . ': ' . (int) $proposed['ctns'];
        }

        return $parts !== [] ? implode(' · ', $parts) : '-';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function formatRemovedProductSummary(array $snapshot): string
    {
        $name = trim((string) ($snapshot['product_name'] ?? ''));

        return $name !== '' ? $name : '-';
    }

    /**
     * @return array<string, mixed>
     */
    private function stageUploadedFiles(Request $request, string $stagingToken): array
    {
        $base = "barang-edit-requests/{$stagingToken}";
        $manifest = [];

        if ($request->hasFile('invoice_file')) {
            $manifest['invoice_file'] = $request->file('invoice_file')->store($base, 'public');
        }

        if ($request->hasFile('good_receipt_file')) {
            $manifest['good_receipt_file'] = $request->file('good_receipt_file')->store($base, 'public');
        }

        if ($request->hasFile('tracking_receipt_file')) {
            $manifest['tracking_receipt_file'] = $request->file('tracking_receipt_file')->store($base, 'public');
        }

        $products = $request->file('products');
        if (is_array($products)) {
            foreach ($products as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach (Barang::productUploadFileKeys() as $fileKey) {
                    if (! isset($row[$fileKey]) || ! $row[$fileKey] instanceof UploadedFile) {
                        continue;
                    }

                    $manifest['products'][(string) $index][$fileKey] = $row[$fileKey]->store("{$base}/products", 'public');
                }
            }
        }

        return $manifest;
    }

    private function rebuildRequest(BarangEditRequest $editRequest): Request
    {
        $payload = $editRequest->proposed_payload;
        $input = array_merge(
            $payload['barang'] ?? [],
            [
                'products' => $payload['products'] ?? [],
                'additional_prices' => $payload['additional_prices'] ?? [],
            ],
        );

        $flags = $payload['flags'] ?? [];
        foreach (['remove_invoice_file', 'remove_good_receipt_file', 'remove_tracking_receipt_file'] as $flag) {
            if (! empty($flags[$flag])) {
                $input[$flag] = '1';
            }
        }

        $request = Request::create(
            '/barang/' . $editRequest->barang_id,
            'PUT',
            $input,
        );
        $request->setUserResolver(static fn () => auth()->user());

        $this->attachStagedFiles($request, $editRequest->staged_files ?? []);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function attachStagedFiles(Request $request, array $manifest): void
    {
        foreach (['invoice_file', 'good_receipt_file', 'tracking_receipt_file'] as $key) {
            if (empty($manifest[$key])) {
                continue;
            }

            $uploaded = $this->uploadedFileFromStorage((string) $manifest[$key]);
            if ($uploaded !== null) {
                $request->files->set($key, $uploaded);
            }
        }

        if (empty($manifest['products']) || ! is_array($manifest['products'])) {
            return;
        }

        foreach ($manifest['products'] as $index => $files) {
            if (! is_array($files)) {
                continue;
            }

            foreach ($files as $fileKey => $path) {
                $uploaded = $this->uploadedFileFromStorage((string) $path);
                if ($uploaded !== null) {
                    $request->files->set("products.{$index}.{$fileKey}", $uploaded);
                }
            }
        }
    }

    private function uploadedFileFromStorage(string $path): ?UploadedFile
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        $absolute = $disk->path($path);
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        return new UploadedFile(
            $absolute,
            basename($absolute),
            $mime,
            null,
            true,
        );
    }

    private function deleteStagedFiles(BarangEditRequest $editRequest): void
    {
        $manifest = $editRequest->staged_files ?? [];
        if ($manifest === []) {
            return;
        }

        $paths = [];
        foreach (['invoice_file', 'good_receipt_file', 'tracking_receipt_file'] as $key) {
            if (! empty($manifest[$key])) {
                $paths[] = (string) $manifest[$key];
            }
        }

        if (! empty($manifest['products']) && is_array($manifest['products'])) {
            foreach ($manifest['products'] as $files) {
                if (! is_array($files)) {
                    continue;
                }
                foreach ($files as $path) {
                    $paths[] = (string) $path;
                }
            }
        }

        Storage::disk('public')->delete($paths);
    }

    private function formatDiffValue(string $field, mixed $value): string
    {
        if ($field === 'no_resi') {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return implode(', ', $decoded);
                }
            }

            if (is_array($value)) {
                return implode(', ', $value);
            }
        }

        if ($field === 'tanggal_masuk') {
            $normalized = $this->normalizeDateValue($value);
            if ($normalized === null) {
                return '-';
            }

            try {
                return (new \DateTimeImmutable($normalized))->format('d M Y');
            } catch (\Exception) {
                return $normalized;
            }
        }

        if (in_array($field, ['price_per_cbm', 'weight_price', 'price'], true)) {
            if ($value === null || $value === '') {
                return '-';
            }

            return RmbFormat::format((float) $value);
        }

        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        return (string) $value;
    }

    private function formatProductDiffValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (in_array($field, ['price_per_unit_rmb', 'total_price_rmb'], true)) {
            return RmbFormat::format((float) $value);
        }

        if (in_array($field, ['gross_weight_kg', 'length_cm', 'width_cm', 'height_cm'], true)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        if ($field === 'volume_cbm') {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
