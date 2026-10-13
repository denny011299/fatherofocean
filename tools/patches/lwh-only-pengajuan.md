# LWH-only Pengajuan Edit — production patch

Apply on the server that has the full `BarangController` with `prepareBarangUpdate` / `submitBarangEditRequest`.

## 1. `app/Support/BarangEditRequestService.php`

Ensure `requiresApproval()` exists (see workspace copy). Whitelist:

- Products: `length_cm`, `width_cm`, `height_cm`
- `barang_dimensions`: `panjang`, `lebar`, `tinggi`

Snapshot must include `photo_path`, `length_photo_path`, `width_photo_path`, `height_photo_path`.
`buildProposedProducts()` / `enrichProductForDiff()` must copy photo paths from snapshot when proposal omits them.

## 2. `app/Http/Controllers/BarangController.php`

Replace the pengajuan gate in `update()`:

```php
$prepared = $this->prepareBarangUpdate($request, $barang);

if (app(BarangEditRequestService::class)->requiresApproval($barang, $request, $prepared)) {
    return $this->submitBarangEditRequest($barang, $request, $prepared);
}

$barang = $this->executePreparedBarangUpdate($barang, $request, $prepared);

return response()->json([
    'success' => true,
    'message' => __('messages.barang_updated'),
    'redirect' => route('barang.index'),
    'saved_directly' => true,
    'data' => ['id' => $barang->id],
    'shipping_label_pdf_url' => $this->shippingLabelPdfUrl($barang),
]);
```

Remove `shouldSubmitEditRequest()` if present — approval is field-based, not role-based.

Pass validated products into `createFromUpdate`:

```php
app(BarangEditRequestService::class)->createFromUpdate(
    $barang,
    $request,
    $data,
    $prepared['data']['products'] ?? null,
);
```

## 3. Behaviour summary

| Change | Result |
|--------|--------|
| L/W/H only | `submitted_for_approval: true`, no DB update until approve |
| Notes, PCS, RMB, GW, marking, etc. only | `saved_directly: true`, immediate save |
| L/W/H + other fields | `submitted_for_approval: true` (safe default) |
| Approver edits L/W/H | Same pengajuan queue (may self-approve) |

## 4. Verify

```bash
php artisan test tests/Unit/BarangEditRequestProductDiffTest.php
php artisan test tests/Feature/BarangEditApprovalTest.php
```
