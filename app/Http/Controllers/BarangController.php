<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\BarangListProductsExport;
use App\Exports\BarangProductsExport;
use App\Imports\BarangImport;
use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\BarangAdditionalPrice;
use App\Models\BarangEditRequest;
use App\Models\BarangDimension;
use App\Models\BarangProduct;
use App\Models\City;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterBranch;
use App\Models\MasterCbmType;
use App\Models\MasterClassification;
use App\Models\MasterCountUnit;
use App\Models\MasterDepartment;
use App\Models\MasterGudangChina;
use App\Models\MasterPacking;
use App\Models\MasterPricingItem;
use App\Models\MasterPackagingType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BarangTrackingService;
use App\Support\BarangEditRequestService;
use App\Support\BarangOriginalPackingList;
use App\Support\BarangShippingPricing;
use App\Support\CompanyRevenueSettings;
use App\Support\MarkingBarangPricing;
use App\Support\MarkingPricingResolver;
use App\Support\ProductsFormPayload;
use App\Support\PublicStorageUrl;
use App\Support\UploadedImageCompressor;
use App\Support\ShippingLabelPdfData;
use App\Support\TerimaBarangPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BarangController extends Controller
{
    /** @var list<string> */
    private const STATUS_FILTER_VALUES = Barang::SHIPMENT_STATUSES;

    /** @var list<string> */
    private const PRODUCT_LOGISTICS_FIELDS = ['ctns', 'length_cm', 'width_cm', 'height_cm'];

    public function index(Request $request): View
    {
        $user = auth()->user();
        $canViewEditPengajuan = $user !== null && (
            $user->can('approve barang') || $user->can('edit barang')
        );
        $pengajuanBadge = 0;

        if ($canViewEditPengajuan) {
            $badgeQuery = BarangEditRequest::query()
                ->where('status', BarangEditRequest::STATUS_PENDING);

            if ($user->can('approve barang')) {
                $pengajuanBadge = (int) $badgeQuery->count();
            } else {
                $pengajuanBadge = (int) $badgeQuery->where('submitted_by', $user->id)->count();
            }
        }

        return view('barang.index', [
            'markings' => Marking::forDropdown()->visibleToUser()->get(Marking::dropdownSelectColumns()),
            'gudangList' => MasterGudangChina::active()->orderBy('name')->get(['id', 'name', 'address']),
            'cities' => $this->barangCityFilterOptions(),
            'partaiOptions' => $this->barangPartaiFilterOptions(),
            'statusOptions' => self::STATUS_FILTER_VALUES,
            'pricingItems' => MasterPricingItem::active()->orderBy('name')->get(['id', 'name']),
            'canViewEditPengajuan' => $canViewEditPengajuan,
            'canApproveEditPengajuan' => $user?->can('approve barang') ?? false,
            'canRejectEditPengajuan' => $user?->can('reject barang') ?? false,
            'pengajuanBadge' => $pengajuanBadge,
            'activeTab' => $request->string('tab')->toString(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(auth()->user()->can('create barang'), 403);

        return view('barang.form', array_merge($this->formViewData(), [
            'barang' => null,
            'barangFormPayload' => null,
        ]));
    }

    public function edit(Barang $barang): View
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        $barang->loadCount('products');

        return view('barang.form', array_merge($this->formViewData($barang), [
            'barang' => $barang,
            'barangFormPayload' => $this->barangFormPayload($barang),
        ]));
    }

    public function data(Request $request): JsonResponse
    {
        $canDownloadInvoice = auth()->user()->can('download barang invoice');
        $canExportBarang = auth()->user()->can('export barang');
        $canExportProducts = auth()->user()->can('export barang products');

        /** @var EloquentCollection<int, Barang> $barangRows */
        $barangRows = $this->barangListQuery($request)->get();

        $this->alignLaggingStatusesForListedBarangs($barangRows);

        $withProducts = $barangRows->filter(static fn (Barang $barang): bool => (int) $barang->products_count > 0);
        if ($withProducts->isNotEmpty()) {
            $this->refreshBarangCbmForList($withProducts, fullProducts: false);
        }

        $markingLookup = $this->markingLookupForBarangs($barangRows);

        $barangs = $barangRows->map(fn (Barang $barang) => $this->mapBarangListRow(
            $barang,
            $canDownloadInvoice,
            $canExportBarang,
            $canExportProducts,
            $markingLookup,
        ));

        return response()->json($barangs);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->can('create barang'), 403);

        ProductsFormPayload::mergeIntoRequest($request);
        $this->stripEmptyFormRows($request);
        $this->stripEmptyProductPhotoFields($request);
        $this->normalizeBarangRequest($request);
        $this->applyDailyRateToRequest($request, isUpdate: false);
        MarkingBarangPricing::applyToRequest($request);
        $this->mergeWarehouseOriginForValidation($request);

        $data = $request->validate(
            array_merge($this->barangStoreRules(), $this->productPhotoValidationRules($request)),
            $this->barangValidationMessages(),
            $this->barangValidationAttributes()
        );

        if ($request->hasFile('invoice_file')) {
            $data['invoice_file'] = $this->storeOriginalPackingListFile(
                $request->file('invoice_file'),
                (string) $data['no_index'],
            );
        }

        $data['no_resi'] = $this->parseNoResiInput($data['no_resi'] ?? null);
        $data['status'] = Barang::STATUS_STUFFING;
        $data['created_by'] = auth()->id();

        if (! empty($data['marking']) && empty($data['user_id'])) {
            $marking = Marking::where('code', $data['marking'])->first();
            if ($marking) {
                if ($marking->user_id === null || (int) $marking->user_id <= 0) {
                    $marking->ensureCustomerUser();
                    $marking->refresh();
                }
                $data['user_id'] = $marking->user_id;
            }
        }

        $this->syncWarehouseOriginFromGudang($data);
        $this->ensureTanggalMasuk($data);

        if (
            ! array_key_exists('company_loyalty_amount', $data)
            || $data['company_loyalty_amount'] === null
            || $data['company_loyalty_amount'] === ''
        ) {
            $data['company_loyalty_amount'] = CompanyRevenueSettings::profitPerShipmentForCbmType($data['cbm_type'] ?? null);
        }

        $this->mergeTerimaBarangUploads($request, $data);

        unset(
            $data['dimensions'],
            $data['products'],
            $data['additional_prices'],
        );
        $this->forgetUnavailableBarangColumns($data);

        $barang = DB::transaction(function () use ($data, $request) {
            @set_time_limit(180);

            $barang = Barang::create($data);
            $this->syncBarangProducts($request, $barang);
            $this->syncBarangAdditionalPrices($request, $barang);

            if ($request->has('dimensions') && is_array($request->input('dimensions'))) {
                foreach ($request->input('dimensions') as $dim) {
                    if (is_array($dim)) {
                        $barang->dimensions()->create($dim);
                    }
                }
                $barang->recalculateTotals();
            }

            return $barang->fresh();
        });

        $this->scheduleAfterStoreSideEffects((int) $barang->id);

        return response()->json([
            'success' => true,
            'message' => __('messages.barang_created'),
            'redirect' => route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
            'shipping_label_pdf_url' => $this->shippingLabelPdfUrl($barang),
        ]);
    }

    public function show(Barang $barang): JsonResponse
    {
        $barang->load('customer', 'dimensions', 'products', 'additionalPrices', 'trackingStatuses', 'packing');
        $data = $barang->toArray();
        $data['invoice_file_url'] = $barang->invoice_file
            ? Storage::disk('public')->url($barang->invoice_file)
            : null;
        $data['products'] = $this->mapProductsForForm($barang->products);
        $data['additional_prices'] = $this->mapAdditionalPricesForForm($barang->additionalPrices);
        $data['terima_barang_pdf_url'] = $this->terimaBarangPdfUrl($barang);
        $data['shipping_label_pdf_url'] = auth()->user()?->can('export barang')
            ? route('barang.shipping-label.pdf', $barang)
            : null;
        $data['products_export_excel_url'] = $barang->products->isNotEmpty() && auth()->user()?->can('export barang products')
            ? route('barang.products.export-excel', $barang)
            : null;
        $data['products_export_url'] = $this->terimaBarangPdfUrl($barang);

        return response()->json($data);
    }

    public function update(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        if (BarangEditRequestService::isEditApprovalEnabled() && $barang->pendingEditRequest()->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('barang.edit_request_pending_exists'),
            ], 422);
        }

        $prepared = $this->prepareBarangUpdate($request, $barang);
        $editRequestService = app(BarangEditRequestService::class);

        if ($editRequestService->requiresApproval($barang, $request, $prepared)) {
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
    }

    public function executeBarangUpdate(Barang $barang, Request $request): Barang
    {
        $isApprovedReplay = $request->attributes->get(BarangEditRequestService::APPLYING_APPROVED_EDIT_ATTRIBUTE) === true;

        if (! $isApprovedReplay) {
            ProductsFormPayload::mergeIntoRequest($request);
            $this->stripEmptyFormRows($request);
        }

        $this->stripEmptyProductPhotoFields($request);
        $this->normalizeBarangRequest($request, isUpdate: true);

        $prepared = $isApprovedReplay
            ? $this->prepareApprovedBarangReplay($request, $barang)
            : $this->prepareBarangUpdate($request, $barang);

        return $this->executePreparedBarangUpdate($barang, $request, $prepared);
    }

    public function destroy(Barang $barang): JsonResponse
    {
        DB::transaction(function () use ($barang) {
            $invoiceIds = $barang->invoiceItems()->pluck('invoice_id')->unique();

            ActivityLog::record('Barang deleted', $barang);
            $barang->invoiceItems()->delete();
            $barang->containers()->detach();
            $barang->delete();

            foreach ($invoiceIds as $invId) {
                Invoice::find($invId)?->recalculate();
            }
        });

        return response()->json(['success' => true, 'message' => 'Barang berhasil dihapus.']);
    }

    public function updateDimensions(Request $request, Barang $barang): JsonResponse
    {
        $data = $request->validate([
            'dimensions' => 'required|array',
            'dimensions.*.id' => 'nullable|exists:barang_dimensions,id',
            'dimensions.*.item_name' => 'nullable|string|max:100',
            'dimensions.*.ctn_no' => 'nullable|string|max:30',
            'dimensions.*.qty_ctn' => 'integer|min:0',
            'dimensions.*.panjang' => 'numeric|min:0',
            'dimensions.*.lebar' => 'numeric|min:0',
            'dimensions.*.tinggi' => 'numeric|min:0',
            'dimensions.*.ctn_count' => 'integer|min:1',
            'dimensions.*.qty' => 'integer|min:0',
            'dimensions.*.count_by' => 'nullable|string|max:20',
            'dimensions.*.gw_per_ctn' => 'numeric|min:0',
            'dimensions.*.gross_weight' => 'numeric|min:0',
        ]);

        $keepIds = [];
        foreach ($data['dimensions'] as $dim) {
            if (!empty($dim['id'])) {
                $existing = BarangDimension::find($dim['id']);
                $existing?->update($dim);
                $keepIds[] = $dim['id'];
            } else {
                $created = $barang->dimensions()->create($dim);
                $keepIds[] = $created->id;
            }
        }

        $barang->dimensions()->whereNotIn('id', $keepIds)->delete();
        $barang->recalculateTotals();

        return response()->json([
            'success' => true,
            'message' => 'Dimensi berhasil diperbarui.',
            'total_cbm' => $barang->fresh()->total_cbm,
            'total_gw' => $barang->fresh()->total_gross_weight,
        ]);
    }

    public function lookupIndex(Request $request): JsonResponse
    {
        $barang = Barang::with('dimensions')
            ->where('no_index', $request->get('no_index'))
            ->first();

        if (!$barang) {
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true, 'data' => $barang]);
    }

    public function downloadInvoice(Barang $barang, ExportController $export): Response
    {
        abort_unless(auth()->user()->can('download barang invoice'), 403);

        $invoice = $barang->invoiceItems()->first()?->invoice;
        if (!$invoice) {
            abort(404);
        }

        return $export->invoicePdf($invoice);
    }

    public function downloadOriginalPackingList(Barang $barang): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(auth()->user()?->can('view barang'), 403);

        $path = $barang->invoice_file;
        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            abort(404, __('messages.file_not_found'));
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'xlsx';
        $downloadName = BarangOriginalPackingList::filename((string) $barang->no_index, $extension);

        return Storage::disk('public')->download($path, $downloadName);
    }

    public function exportTerimaBarangPdf(Barang $barang): Response
    {
        abort_unless(auth()->user()->can('export barang'), 403);

        $payload = TerimaBarangPdfData::forBarang($barang);
        $pdf = Pdf::loadView('exports.terima-barang-pdf', $payload);

        return $pdf->download(TerimaBarangPdfData::filename($barang));
    }

    public function exportShippingLabelPdf(Barang $barang): Response
    {
        abort_unless(auth()->user()?->can('export barang'), 403);

        $payload = ShippingLabelPdfData::forBarang($barang);

        $pdf = Pdf::loadView('exports.shipping-label-pdf', $payload)
            ->setPaper([0, 0, ShippingLabelPdfData::PAPER_WIDTH_PT, ShippingLabelPdfData::PAPER_HEIGHT_PT])
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', true);

        return $pdf->stream(ShippingLabelPdfData::filename($barang));
    }

    public function exportProductsExcel(Barang $barang): BinaryFileResponse
    {
        abort_unless(auth()->user()->can('export barang products'), 403);

        if (!$barang->products()->exists()) {
            abort(404);
        }

        $filename = 'barang-products-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $barang->no_index ?? (string) $barang->id) . '.xlsx';

        return Excel::download(new BarangProductsExport($barang), $filename);
    }

    public function exportListProductsExcel(Request $request): BinaryFileResponse
    {
        abort_unless(auth()->user()->can('export barang'), 403);

        /** @var EloquentCollection<int, Barang> $barangRows */
        $barangRows = $this->barangListQuery($request, fullProducts: true)->get();
        $this->refreshBarangCbmForList($barangRows, fullProducts: true);

        return Excel::download(
            new BarangListProductsExport($barangRows),
            BarangListProductsExport::filename(),
        );
    }

    public function uploadProductDimensionPhoto(Request $request, Barang $barang, BarangProduct $product): JsonResponse
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        if ((int) $product->barang_id !== (int) $barang->id) {
            abort(404);
        }

        if ($product->parent_id !== null) {
            return response()->json([
                'success' => false,
                'message' => __('barang.dimension_proof_parent_only'),
            ], 422);
        }

        $validated = $request->validate([
            'dimension' => 'required|in:length,width,height',
            'remove' => 'nullable|boolean',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:'.self::PRODUCT_PHOTO_MAX_KB,
        ]);

        $dimension = $validated['dimension'];
        $pathKey = "{$dimension}_photo_path";

        if ($request->boolean('remove')) {
            if ($product->{$pathKey}) {
                Storage::disk('public')->delete($product->{$pathKey});
            }
            $product->{$pathKey} = null;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => __('barang.dimension_proof_removed'),
                'dimension' => $dimension,
                "has_{$dimension}_photo" => false,
                $pathKey => null,
                "{$dimension}_photo_url" => null,
            ]);
        }

        if (! $request->hasFile('photo')) {
            return response()->json([
                'success' => false,
                'message' => __('barang.dimension_proof_file_required'),
            ], 422);
        }

        $uploaded = $request->file('photo');
        if ($product->{$pathKey}) {
            Storage::disk('public')->delete($product->{$pathKey});
        }
        $product->{$pathKey} = $uploaded->store('barang-products/dimensions', 'public');
        $product->save();

        $path = BarangProduct::normalizeStoragePath($product->{$pathKey});
        $url = PublicStorageUrl::url($path);

        return response()->json([
            'success' => true,
            'message' => __('barang.dimension_proof_upload_success'),
            'dimension' => $dimension,
            "has_{$dimension}_photo" => true,
            $pathKey => $path,
            "{$dimension}_photo_url" => $url,
        ]);
    }

    public function uploadProductPhoto(Request $request, Barang $barang, BarangProduct $product): JsonResponse
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        if ((int) $product->barang_id !== (int) $barang->id) {
            abort(404);
        }

        $request->validate([
            'remove' => 'nullable|boolean',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:'.self::PRODUCT_PHOTO_MAX_KB,
        ]);

        if ($request->boolean('remove')) {
            if ($product->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $product->photo_path = null;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => __('barang.product_photo_removed'),
                'photo_path' => null,
                'photo_url' => null,
                'has_photo' => false,
            ]);
        }

        if (! $request->hasFile('photo')) {
            return response()->json([
                'success' => false,
                'message' => __('barang.product_photo_required'),
            ], 422);
        }

        $uploaded = $request->file('photo');
        if ($product->photo_path) {
            Storage::disk('public')->delete($product->photo_path);
        }
        $product->photo_path = $this->storeProductPhotoFile($uploaded, 'barang-products');
        $product->save();

        $path = BarangProduct::normalizeStoragePath($product->photo_path);

        return response()->json([
            'success' => true,
            'message' => __('barang.product_photo_upload_success'),
            'photo_path' => $path,
            'photo_url' => PublicStorageUrl::url($path),
            'has_photo' => true,
        ]);
    }

    public function stageProductPhoto(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        return $this->storeStagedProductPhoto($request, 'barang-product-staging/barang-' . $barang->id);
    }

    public function stageProductPhotoForCreate(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->can('create barang'), 403);

        return $this->storeStagedProductPhoto($request, 'barang-product-staging/user-' . auth()->id());
    }

    private function storeStagedProductPhoto(Request $request, string $baseDir): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:'.self::PRODUCT_PHOTO_MAX_KB,
        ]);

        $path = $this->storeProductPhotoFile($request->file('photo'), rtrim($baseDir, '/') . '/products');
        $normalized = BarangProduct::normalizeStoragePath($path);

        return response()->json([
            'success' => true,
            'message' => __('barang.product_photo_upload_success'),
            'photo_path' => $normalized,
            'photo_url' => PublicStorageUrl::url($normalized),
            'has_photo' => true,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => 'required|mimes:xlsx,xls,csv|max:10240'],
            [],
            $this->barangValidationAttributes()
        );

        try {
            Excel::import(new BarangImport(), $request->file('file'));

            return response()->json(['success' => true, 'message' => 'Data preload berhasil diimport.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 422);
        }
    }

    private function additionalPriceChanged(BarangAdditionalPrice $record, array $payload): bool
    {
        return (int) $record->sort_order !== (int) ($payload['sort_order'] ?? 0)
            || trim((string) $record->name) !== trim((string) ($payload['name'] ?? ''))
            || abs((float) $record->price - (float) ($payload['price'] ?? 0)) > 0.0001;
    }

    private function applyApprovalOnSave(array &$data): void
    {
        if ($this->canAutoApproveBarang()) {
            $data['approval_status'] = Barang::APPROVAL_APPROVED;
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
            $data['rejection_note'] = null;

            return;
        }

        $data['approval_status'] = Barang::APPROVAL_PENDING;
        $data['approved_by'] = null;
        $data['approved_at'] = null;
        $data['rejection_note'] = null;
    }

    private function applyBarangListFilters(Builder $query, Request $request): void
    {
        $tanggalFrom = $request->string('tanggal_from')->toString();
        $tanggalTo = $request->string('tanggal_to')->toString();

        if ($tanggalFrom !== '' && $tanggalTo !== '') {
            $from = Carbon::parse($tanggalFrom)->startOfDay();
            $to = Carbon::parse($tanggalTo)->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            $query->whereBetween('tanggal_masuk', [$from, $to]);
        } elseif ($tanggalFrom !== '') {
            $query->whereDate('tanggal_masuk', Carbon::parse($tanggalFrom)->toDateString());
        } elseif ($tanggalTo !== '') {
            $query->whereDate('tanggal_masuk', '<=', Carbon::parse($tanggalTo)->toDateString());
        }

        $noIndex = trim($request->string('no_index')->toString());
        if ($noIndex !== '') {
            $query->where('no_index', 'like', '%' . $noIndex . '%');
        }

        $noResi = trim($request->string('no_resi')->toString());
        if ($noResi !== '') {
            $query->where(static function (Builder $resiQuery) use ($noResi): void {
                $resiQuery
                    ->where('tracking_number', 'like', '%' . $noResi . '%')
                    ->orWhere('no_resi', 'like', '%' . $noResi . '%');
            });
        }

        $markingValues = $this->markingFilterValues($request);
        if ($markingValues !== []) {
            $query->whereIn('marking', $markingValues);
        }

        $status = $request->string('status')->toString();
        if ($status !== '' && in_array($status, self::STATUS_FILTER_VALUES, true)) {
            $query->where('status', $status);

            if ($status === Barang::STATUS_STUFFING) {
                $query->notInContainer();
            }
        }

        $city = trim($request->string('city')->toString());
        if ($city === '') {
            $city = trim($request->string('destination')->toString());
        }
        if ($city !== '') {
            $query->where('destination', $city);
        }

        $gudangId = $request->input('gudang_china_id');
        if ($gudangId !== null && $gudangId !== '') {
            $query->where('gudang_china_id', $gudangId);
        }

        $approvalFilter = $request->get('approval_status');
        if (is_string($approvalFilter) && $approvalFilter !== '') {
            $query->where('approval_status', $approvalFilter);
        }

        $pricingItemIds = $request->input('pricing_item_id');
        if ($pricingItemIds !== null && $pricingItemIds !== '' && $pricingItemIds !== []) {
            $query->filterByPricingItemId($pricingItemIds);
        }

        $partai = trim($request->string('partai')->toString());
        if ($partai === 'belum_di_muat') {
            $query->whereDoesntHave('containers');
        } elseif ($partai !== '') {
            $query->whereHas('containers', static function (Builder $containerQuery) use ($partai): void {
                $containerQuery->where('partai', $partai);
            });
        }
    }

    private function applyDailyRateToRequest(Request $request, bool $isUpdate): void
    {
        if ($isUpdate) {
            $submitted = $request->input('rate');
            if ($submitted !== null && $submitted !== '') {
                return;
            }
        }

        $value = SystemSetting::getValue('daily_rate');
        if ($value === null || $value === '') {
            return;
        }

        $request->merge(['rate' => (string) $value]);
    }

    private function applyMarkingCostPricingToRequest(Request $request): void
    {
        $marking = $this->resolveMarking($request);
        if ($marking === null) {
            return;
        }

        $row = $this->pickMarkingPricingRow(
            $marking,
            $this->normalizeMarkingMatchKey($request->input('cbm_type'))
        );

        $rates = MarkingPricingResolver::modalRatesFromPricingRow($row);

        $request->merge([
            'cost_price_per_cbm' => (string) $rates['volume_price'],
            'cost_weight_price' => (string) $rates['gw_price'],
        ]);
    }

    private function applyMarkingPricingToRequest(Request $request): void
    {
        $marking = $this->resolveMarking($request);
        if ($marking === null) {
            return;
        }

        $row = $this->pickMarkingPricingRow(
            $marking,
            $this->normalizeMarkingMatchKey($request->input('cbm_type'))
        );

        $volumePrice = $row !== null ? (float) ($row->volume_price ?? 0) : 0.0;
        $gwPrice = $row !== null ? (float) ($row->gw_price ?? 0) : 0.0;

        $request->merge([
            'price_per_cbm' => (string) $volumePrice,
            'weight_price' => (string) $gwPrice,
        ]);
    }

    private function assertBarangVisibleToUser(Barang $barang): void
    {
        abort_unless(Marking::barangVisibleToUser($barang), 403);
    }

    private function barangAuditSnapshot(Barang $barang): array
    {
        return $barang->only([
            'id', 'no_index', 'marking', 'status', 'user_id', 'gudang_china_id',
            'total_cbm', 'total_gross_weight', 'price_per_cbm', 'weight_price',
            'approval_status', 'tanggal_masuk',
        ]);
    }

    private function barangCityFilterOptions(): Collection
    {
        $branchIds = Marking::query()
            ->visibleToUser()
            ->whereNotNull('branch_id')
            ->whereHas('barangs')
            ->distinct()
            ->pluck('branch_id');

        if ($branchIds->isEmpty()) {
            return collect();
        }

        return MasterBranch::query()
            ->whereIn('id', $branchIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (MasterBranch $branch) => trim((string) ($branch->name ?: $branch->code)))
            ->filter(static fn (string $label) => $label !== '')
            ->unique()
            ->values();
    }

    private function barangCrudJsonResponse(Barang $barang, string $message, bool $redirectToIndex = false): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'redirect' => $redirectToIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ];

        if (! $redirectToIndex) {
            $barang->load(['products', 'additionalPrices']);
            $payload['products'] = $this->mapProductsForForm($barang->products);
            $payload['additional_prices'] = $this->mapAdditionalPricesForForm($barang->additionalPrices);
            $payload['terima_barang_pdf_url'] = $this->terimaBarangPdfUrl($barang);
            $payload['shipping_label_pdf_url'] = auth()->user()?->can('export barang')
                ? route('barang.shipping-label.pdf', $barang)
                : null;
            $payload['products_export_excel_url'] = $barang->products->isNotEmpty() && auth()->user()?->can('export barang products')
                ? route('barang.products.export-excel', $barang)
                : null;
            // Legacy alias for PDF Terima Barang (not Excel).
            $payload['products_export_url'] = $this->terimaBarangPdfUrl($barang);
            $payload['original_pl_download_url'] = $this->originalPackingListDownloadUrl($barang);
        }

        return response()->json($payload);
    }

    private function resolvePricingItemLabel(?string $cbmType): string
    {
        $raw = trim((string) $cbmType);
        if ($raw === '') {
            return '';
        }

        if (ctype_digit($raw)) {
            $name = MasterPricingItem::query()->whereKey((int) $raw)->value('name');

            return trim((string) ($name ?? ''));
        }

        return $raw;
    }

    private function barangFormPayload(Barang $barang): array
    {
        $barang->loadMissing('customer', 'dimensions', 'products');
        $barang->load(['additionalPrices' => static fn ($q) => $q->orderBy('sort_order')]);

        return [
            'id' => $barang->id,
            'user_id' => $barang->user_id,
            'gudang_china_id' => $barang->gudang_china_id,
            'no_index' => $barang->no_index,
            'marking' => $barang->marking,
            'supplier_chinese' => $barang->supplier_chinese,
            'customer_name' => $barang->customer_name,
            'ctns' => $barang->ctns,
            'price' => $barang->price,
            'cbm_type' => $barang->cbm_type,
            'cbm_type_label' => $this->resolvePricingItemLabel($barang->cbm_type),
            'rate' => $barang->rate,
            'weight' => $barang->weight,
            'price_per_cbm' => $barang->price_per_cbm,
            'weight_price' => $barang->weight_price,
            'weight_threshold_kg' => $barang->weight_threshold_kg,
            'packing_id' => $barang->packing_id,
            'other' => $barang->other,
            'notes' => $barang->notes,
            'no_resi' => $barang->no_resi ?? [],
            'tanggal_masuk' => $barang->tanggal_masuk?->toDateString(),
            'invoice_file' => $barang->invoice_file,
            'invoice_file_url' => $barang->invoice_file
                ? Storage::disk('public')->url($barang->invoice_file)
                : null,
            'good_receipt_path' => $barang->good_receipt_path,
            'good_receipt_url' => PublicStorageUrl::url($barang->good_receipt_path),
            'tracking_receipt_path' => $barang->tracking_receipt_path,
            'tracking_receipt_url' => PublicStorageUrl::url($barang->tracking_receipt_path),
            'products' => $this->mapProductsForForm($barang->products),
            'additional_prices' => $this->mapAdditionalPricesForForm($barang->additionalPrices),
            'dimensions' => $barang->dimensions->toArray(),
            'terima_barang_pdf_url' => $this->terimaBarangPdfUrl($barang),
            'shipping_label_pdf_url' => auth()->user()?->can('export barang')
                ? route('barang.shipping-label.pdf', $barang)
                : null,
            'products_export_excel_url' => $barang->products->isNotEmpty() && auth()->user()?->can('export barang products')
                ? route('barang.products.export-excel', $barang)
                : null,
            // Legacy alias — keep as PDF so old JS never treats this as Excel.
            'products_export_url' => $this->terimaBarangPdfUrl($barang),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBarangListRow(
        Barang $barang,
        bool $canDownloadInvoice,
        bool $canExportBarang,
        bool $canExportProducts,
        Collection $markingLookup,
    ): array {
        $hasFinanceInvoice = $barang->invoiceItems->isNotEmpty();
        $markingCode = trim((string) ($barang->marking ?? ''));
        $markingRecord = $markingCode !== ''
            ? $markingLookup->get($markingCode)
            : null;

        $branchCity = trim((string) (
            $markingRecord?->branch?->name
            ?? $markingRecord?->branch?->code
            ?? $barang->destination
            ?? ''
        ));
        $gudangName = trim((string) ($barang->gudangChina?->name ?? $barang->warehouse_origin ?? ''));
        $notes = trim((string) ($barang->notes ?? ''));

        return [
            'id' => $barang->id,
            'no_index' => $barang->no_index,
            'no_po' => $barang->no_po,
            'marking' => $barang->marking,
            'customer_name' => $barang->customer_name,
            'marketing_name' => $markingRecord?->sales?->name,
            'tanggal_masuk_display' => $barang->tanggal_masuk
                ? $barang->tanggal_masuk->locale(app()->getLocale())->translatedFormat('d M Y')
                : null,
            'gudang_name' => $gudangName !== '' ? $gudangName : null,
            'branch_city' => $branchCity !== '' ? $branchCity : null,
            'barang_summary' => $this->barangItemsSummary($barang),
            'notes' => $notes !== '' ? $notes : null,
            'packing_name' => $barang->packing?->name,
            'partai_display' => $this->barangPartaiLabel($barang),
            'resi_lines' => $barang->listResiLines(),
            'ctns' => $barang->ctns,
            'total_cbm' => $barang->total_cbm,
            'total_gross_weight' => $barang->total_gross_weight,
            'no_resi' => $barang->no_resi ?? [],
            'status' => $barang->status,
            'raw_status' => $barang->status,
            'status_badge' => $barang->status_badge,
            'approval_status' => $barang->approval_status,
            'approval_status_badge' => $barang->approval_status_badge,
            'rejection_note' => $barang->rejection_note,
            'has_invoice' => (bool) $barang->invoice_file,
            'has_finance_invoice' => $hasFinanceInvoice,
            'invoice_download_url' => $canDownloadInvoice && $hasFinanceInvoice
                ? route('barang.invoice.pdf', $barang)
                : null,
            'can_withdraw' => Barang::userCanSetDitarikKembali(auth()->user())
                && $barang->canWithdrawToDitarikKembali(),
            'can_reactivate' => Barang::userCanSetDitarikKembali(auth()->user())
                && Barang::isDitarikKembaliStatus((string) $barang->status),
            'terima_barang_pdf_url' => $canExportBarang ? $this->terimaBarangPdfUrl($barang) : null,
            'products_export_excel_url' => $canExportProducts && (int) $barang->products_count > 0
                ? route('barang.products.export-excel', $barang)
                : null,
            // Do not point this at Excel — Index/Form used to fall back PDF button here.
            'products_export_url' => $canExportBarang ? $this->terimaBarangPdfUrl($barang) : null,
        ];
    }

    private function barangItemsSummary(Barang $barang): string
    {
        if ($barang->relationLoaded('products') && $barang->products->isNotEmpty()) {
            $products = $barang->products->whereNull('parent_id');
            if ($products->isEmpty()) {
                $products = $barang->products;
            }

            $labels = $products
                ->map(static function (BarangProduct $product): string {
                    $name = trim((string) ($product->product_name ?? ''));
                    if ($name !== '') {
                        return $name;
                    }

                    return trim((string) ($product->material ?? ''));
                })
                ->filter(static fn (string $label) => $label !== '')
                ->unique()
                ->values();

            if ($labels->isNotEmpty()) {
                return $labels->implode(', ');
            }
        }

        $form = trim((string) ($barang->form ?? ''));
        if ($form !== '') {
            return $form;
        }

        $supplier = trim((string) ($barang->supplier_chinese ?? ''));
        if ($supplier !== '') {
            return $supplier;
        }

        return '-';
    }

    private function barangListQuery(Request $request, bool $fullProducts = false): Builder
    {
        $query = Barang::query()
            ->with([
                'invoiceItems',
                'gudangChina:id,name',
                'packing:id,name',
                'containers:id,partai',
                'products' => static function ($q) use ($fullProducts): void {
                    $q->orderBy('sort_order');
                    if (! $fullProducts) {
                        $q->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order');
                    }
                },
            ])
            ->withCount('products');

        Marking::restrictBarangsQuery($query);
        $this->applyBarangListFilters($query, $request);

        return $query->orderBy('tanggal_masuk')->orderBy('id');
    }

    /**
     * Upgrade stuffing/etc. barangs that share a container already at ETD/ETA/bongkar.
     *
     * @param  EloquentCollection<int, Barang>|\Illuminate\Support\Collection<int, Barang>  $barangRows
     */
    private function alignLaggingStatusesForListedBarangs($barangRows): void
    {
        $containerIds = $barangRows
            ->flatMap(static fn (Barang $barang) => $barang->containers->pluck('id'))
            ->unique()
            ->filter()
            ->values();

        if ($containerIds->isEmpty()) {
            return;
        }

        $aligned = false;
        Container::query()
            ->whereIn('id', $containerIds->all())
            ->with('latestTracking')
            ->get()
            ->each(function (Container $container) use (&$aligned): void {
                if ($container->alignLaggingBarangsToCurrentTracking(auth()->id()) > 0) {
                    $aligned = true;
                }
            });

        if (! $aligned) {
            return;
        }

        foreach ($barangRows as $barang) {
            if ($barang->containers->isEmpty()) {
                continue;
            }

            $barang->refresh();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Barang>|EloquentCollection<int, Barang>  $barangRows
     */
    private function refreshBarangCbmForList($barangRows, bool $fullProducts = false): void
    {
        foreach ($barangRows as $barang) {
            if ((int) $barang->products_count <= 0) {
                continue;
            }

            $barang->recalculateTotals();
            $barang->refresh();
            $barang->unsetRelation('products');
            $barang->load([
                'products' => static function ($q) use ($fullProducts): void {
                    $q->orderBy('sort_order');
                    if (! $fullProducts) {
                        $q->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order');
                    }
                },
            ]);
        }
    }

    private function barangPartaiFilterOptions(): Collection
    {
        return Container::query()
            ->whereNotNull('partai')
            ->where('partai', '!=', '')
            ->distinct()
            ->orderBy('partai')
            ->pluck('partai')
            ->map(static fn (mixed $partai): string => trim((string) $partai))
            ->filter(static fn (string $partai): bool => $partai !== '')
            ->values();
    }

    private function barangPartaiLabel(Barang $barang): ?string
    {
        $partais = $barang->containers
            ->pluck('partai')
            ->map(static fn ($partai) => trim((string) $partai))
            ->filter()
            ->unique()
            ->values();

        if ($partais->isNotEmpty()) {
            return $partais->implode(', ');
        }

        return null;
    }

    private function barangSavedJson(Barang $barang, string $message, bool $toIndex = false): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'redirect' => $toIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ]);
    }

    private function barangSavedPayload(Barang $barang, string $message, bool $toIndex = false): array
    {
        return [
            'success' => true,
            'message' => $message,
            'redirect' => $toIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ];
    }

    private function barangStoreRules(): array
    {
        return [
            'no_index' => 'required|string|max:50|unique:barangs',
            'no_po' => 'nullable|string|max:50',
            'marking' => 'nullable|string|max:100',
            'supplier_chinese' => 'nullable|string|max:100',
            'customer_name' => 'required|string|max:150',
            'user_id' => 'nullable|exists:users,id',
            'gudang_china_id' => 'nullable|integer',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'nama_barang' => 'nullable|string|max:255',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'total_invoice' => 'nullable|string|max:100',
            'invoice_file' => ['nullable', BarangOriginalPackingList::uploadValidationRule()],
            'good_receipt_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:'.self::GOOD_RECEIPT_MAX_KB,
            'tracking_receipt_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'weight_price' => 'nullable|numeric|min:0',
            'cbm_threshold' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'packing_id' => 'nullable|exists:master_packings,id',
            'no_resi' => 'nullable|string',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
            'products_payload' => 'nullable|string',
            'dimensions' => 'nullable|array',
            'dimensions.*.item_name' => 'nullable|string|max:100',
            'dimensions.*.ctn_no' => 'nullable|string|max:30',
            'dimensions.*.qty_ctn' => 'nullable|integer|min:0',
            'dimensions.*.panjang' => 'nullable|numeric|min:0',
            'dimensions.*.lebar' => 'nullable|numeric|min:0',
            'dimensions.*.tinggi' => 'nullable|numeric|min:0',
            'dimensions.*.ctn_count' => 'nullable|integer|min:1',
            'dimensions.*.qty' => 'nullable|integer|min:0',
            'dimensions.*.count_by' => 'nullable|string|max:20',
            'dimensions.*.gw_per_ctn' => 'nullable|numeric|min:0',
            'dimensions.*.gross_weight' => 'nullable|numeric|min:0',
            'additional_prices' => 'nullable|array',
            'additional_prices.*.id' => 'nullable|integer',
            'additional_prices.*.name' => 'nullable|string|max:200',
            'additional_prices.*.price' => 'nullable|numeric|min:0',
            'additional_prices_clear' => 'nullable|boolean',
        ];
    }

    private function barangUpdateRules(Barang $barang): array
    {
        return [
            'no_index' => 'required|string|max:50|unique:barangs,no_index,'.$barang->id,
            'no_po' => 'nullable|string|max:50',
            'marking' => 'nullable|string|max:100',
            'supplier_chinese' => 'nullable|string|max:100',
            'customer_name' => 'required|string|max:150',
            'user_id' => 'nullable|exists:users,id',
            'gudang_china_id' => 'nullable|integer',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'nama_barang' => 'nullable|string|max:255',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'total_invoice' => 'nullable|string|max:100',
            'invoice_file' => ['nullable', BarangOriginalPackingList::uploadValidationRule()],
            'remove_invoice_file' => 'nullable|boolean',
            'good_receipt_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:'.self::GOOD_RECEIPT_MAX_KB,
            'remove_good_receipt_file' => 'nullable|boolean',
            'tracking_receipt_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_tracking_receipt_file' => 'nullable|boolean',
            'weight_price' => 'nullable|numeric|min:0',
            'cbm_threshold' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'packing_id' => 'nullable|exists:master_packings,id',
            'no_resi' => 'nullable|string',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
            'products_payload' => 'nullable|string',
            'products' => 'nullable|array',
            'products.*.id' => 'nullable|integer',
            'products.*.product_name' => 'nullable|string|max:200',
            'products.*.ctns' => 'nullable|integer|min:0',
            'products.*.pcs_per_box' => 'nullable|integer|min:0',
            'products.*.length_cm' => 'nullable|numeric|min:0',
            'products.*.width_cm' => 'nullable|numeric|min:0',
            'products.*.height_cm' => 'nullable|numeric|min:0',
            'products.*.gross_weight_kg' => 'nullable|numeric|min:0',
            'products.*.price_per_unit_rmb' => 'nullable|numeric|min:0',
            'products.*.total_price_rmb' => 'nullable|numeric|min:0',
            'additional_prices' => 'nullable|array',
            'additional_prices.*.id' => 'nullable|integer',
            'additional_prices.*.name' => 'nullable|string|max:200',
            'additional_prices.*.price' => 'nullable|numeric|min:0',
            'additional_prices_clear' => 'nullable|boolean',
        ];
    }

    private function barangValidationAttributes(): array
    {
        return [
            'no_index' => 'No. Index',
            'no_po' => 'No. PO',
            'marking' => 'Marking',
            'supplier_chinese' => 'No. Tel / Chinese',
            'customer_name' => 'Nama Customer',
            'user_id' => 'Customer',
            'warehouse_origin' => 'Gudang',
            'tanggal_masuk' => 'Tanggal Masuk',
            'ctns' => 'Coly',
            'pcs' => 'PCS',
            'price' => 'Harga',
            'material' => 'Material',
            'form' => 'Bentuk',
            'material_desc' => 'Material (English)',
            'insurance_value' => 'Nilai Asuransi',
            'insurance_rate' => 'Asuransi (%)',
            'insurance_nominal_standar' => 'Nominal Standar Asuransi',
            'cbm_type' => 'Tipe CBM',
            'nama_barang' => 'Nama Barang',
            'rate' => 'Rate',
            'weight' => 'Berat (kg)',
            'price_per_cbm' => 'Harga CBM',
            'total_invoice' => 'Total Invoice',
            'invoice_file' => 'File Invoice',
            'weight_price' => 'Harga Berat (per kg)',
            'cbm_threshold' => 'Maks CBM (Threshold)',
            'destination' => 'Tujuan',
            'other' => 'Other',
            'packing_id' => 'Packing',
            'no_resi' => 'Nomor Resi',
            'notes' => 'Catatan',
            'notes_id' => 'Catatan (ID)',
            'dimensions' => 'Dimensi',
            'dimensions.*.item_name' => 'Nama Item Dimensi',
            'dimensions.*.ctn_no' => 'CTN# Dimensi',
            'dimensions.*.qty_ctn' => 'QTY CTN Dimensi',
            'dimensions.*.panjang' => 'Panjang Dimensi',
            'dimensions.*.lebar' => 'Lebar Dimensi',
            'dimensions.*.tinggi' => 'Tinggi Dimensi',
            'dimensions.*.ctn_count' => 'Jumlah CTN Dimensi',
            'dimensions.*.qty' => 'QTY Dimensi',
            'dimensions.*.count_by' => 'Count By Dimensi',
            'dimensions.*.gw_per_ctn' => 'GW/CTN Dimensi',
            'dimensions.*.gross_weight' => 'Total GW Dimensi',
        ];
    }

    private function barangValidationMessages(): array
    {
        $messages = [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'max.string' => ':attribute maksimal :max karakter.',
            'max.file' => ':attribute maksimal :max kilobyte.',
            'unique' => ':attribute sudah digunakan.',
            'date' => ':attribute harus berformat tanggal yang valid.',
            'integer' => ':attribute harus berupa angka bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'min.numeric' => ':attribute minimal :min.',
            'min.integer' => ':attribute minimal :min.',
            'max.numeric' => ':attribute maksimal :max.',
            'exists' => ':attribute yang dipilih tidak valid.',
            'file' => ':attribute harus berupa file.',
            'mimes' => ':attribute harus berformat :values.',
            'array' => ':attribute tidak valid.',
            'boolean' => ':attribute tidak valid.',
        ];

        $numericFields = [
            'price',
            'insurance_nominal_standar',
            'rate',
            'price_per_cbm',
            'weight_price',
            'cbm_threshold',
            'insurance_value',
            'insurance_rate',
            'weight',
        ];

        foreach ($numericFields as $field) {
            $messages["{$field}.numeric"] = ':attribute harus berupa angka.';
            $messages["{$field}.min"] = ':attribute minimal :min.';
            $messages["{$field}.max"] = ':attribute maksimal :max.';
        }

        $messages['insurance_rate.max'] = 'Rate asuransi maksimal :max%.';
        $messages['invoice_file.extensions'] = 'File Invoice harus berformat Excel (.xls, .xlsx), PDF, CSV, Word, atau gambar.';
        $messages['invoice_file.max'] = 'File Invoice maksimal 10 MB.';

        return $messages;
    }

    private function canAutoApproveBarang(): bool
    {
        return auth()->user()?->can('approve barang') ?? false;
    }

    private function canEditBarangProductLogistics(): bool
    {
        return auth()->user()?->can('edit barang product logistics') ?? false;
    }

    private function dispatchBarangAfterStore(int $barangId, ?int $actorId): void
    {
        $sideEffects = $this->barangSideEffects;
        $pending = dispatch(static function () use ($sideEffects, $barangId, $actorId): void {
            $sideEffects->afterStore($barangId, $actorId);
        });

        if ($pending instanceof PendingDispatch) {
            $pending->afterResponse();
        }
    }

    private function enforceProductLogisticsPermissions(Request $request, Barang $barang): void
    {
        if (
            $request->attributes->get(BarangEditRequestService::APPLYING_APPROVED_EDIT_ATTRIBUTE) === true
            || $this->canEditBarangProductLogistics()
            || ! $request->has('products')
        ) {
            return;
        }

        $rows = $request->input('products', []);
        if (!is_array($rows)) {
            return;
        }

        $existingById = $barang->exists
            ? $barang->products()->get()->keyBy('id')
            : collect();

        $blocked = false;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (empty($row['id'])) {
                continue;
            }

            foreach (self::PRODUCT_LOGISTICS_FIELDS as $field) {
                if (!empty($row['id']) && $existingById->has((int) $row['id'])) {
                    /** @var BarangProduct $product */
                    $product = $existingById->get((int) $row['id']);
                    if ($this->productLogisticsValueChanged($row, $product, $field)) {
                        $blocked = true;
                    }
                    $rows[$index][$field] = $product->{$field};
                } elseif ($this->productLogisticsIncomingNotEmpty($row, $field)) {
                    $blocked = true;
                    $rows[$index][$field] = $field === 'ctns' ? 0 : 0;
                }
            }
        }

        $request->merge(['products' => $rows]);

        if ($blocked) {
            ActivityLog::record('Barang product logistics change blocked', $barang);
        }
    }

    private function ensureTanggalMasuk(array &$data): void
    {
        if (! array_key_exists('tanggal_masuk', $data) || $data['tanggal_masuk'] === null || $data['tanggal_masuk'] === '') {
            $data['tanggal_masuk'] = now()->toDateString();
        }
    }

    private function executePreparedBarangUpdate(Barang $barang, Request $request, array $prepared): Barang
    {
        @set_time_limit(180);

        $data = $prepared['data'];
        $old = $prepared['old'];

        if ($request->hasFile('invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = $this->storeOriginalPackingListFile(
                $request->file('invoice_file'),
                (string) $barang->no_index,
            );
        } elseif ($request->boolean('remove_invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = null;
        }

        $this->syncTerimaBarangUploads($request, $barang, $data);

        unset(
            $data['remove_invoice_file'],
            $data['remove_good_receipt_file'],
            $data['remove_tracking_receipt_file'],
            $data['good_receipt_file'],
            $data['tracking_receipt_file'],
            $data['dimensions'],
            $data['products'],
            $data['additional_prices'],
            $data['company_loyalty_amount'],
        );

        $this->syncWarehouseOriginFromGudang($data);
        $this->ensureTanggalMasuk($data);
        $this->forgetUnavailableBarangColumns($data);

        DB::transaction(function () use (&$barang, $data, $request, $old): void {
            $barang->update($data);
            $this->enforceProductLogisticsPermissions($request, $barang);
            $this->syncBarangProducts($request, $barang);
            $this->syncBarangAdditionalPrices($request, $barang);
            $barang->refresh();
        });

        $this->scheduleAfterUpdateSideEffects((int) $barang->id, $old, $this->barangAuditSnapshot($barang));

        return $barang;
    }

    private function formViewData(?Barang $barang = null): array
    {
        $markings = Marking::forDropdown()
            ->visibleToUser()
            ->with([
                'pricings' => static fn ($query) => $query
                    ->orderBy('id')
                    ->with('pricingItem:id,name'),
            ])
            ->get(['id', 'code', 'customer_name', 'user_id', 'max_weight_kg']);

        return [
            'pricingItems' => MasterPricingItem::active()->orderBy('name')->get(['id', 'name']),
            'cbmTypes' => MasterCbmType::active()->orderBy('name')->get(),
            'classifications' => MasterClassification::active()->orderBy('name')->get(),
            'countUnits' => MasterCountUnit::active()->orderBy('name')->get(),
            'markings' => $markings,
            'markingPricingsIndex' => $this->markingPricingsIndexFromMarkings($markings),
            'packings' => MasterPacking::active()->orderBy('name')->get(),
            'gudangList' => $this->gudangOptionsForForm($barang),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Marking>  $markings
     * @return array<int, array{max_weight_kg: float|int|string|null, minimum_charge: string, pricings: list<array<string, mixed>>}>
     */
    private function markingPricingsIndexFromMarkings(\Illuminate\Support\Collection $markings): array
    {
        $index = [];

        foreach ($markings as $marking) {
            $index[(int) $marking->id] = [
                'max_weight_kg' => $marking->max_weight_kg ?? 0,
                'minimum_charge' => $marking->resolvedMinimumCharge(),
                'pricings' => $marking->pricings
                    ->map(static function (MarkingPricing $pricing): array {
                        return [
                            'pricing_item_id' => $pricing->pricing_item_id,
                            'pricing_item_name' => $pricing->pricingItem?->name,
                            'item_name' => Marking::hasColumnCached('marking_pricings', 'item_name')
                                ? ($pricing->item_name ?? null)
                                : null,
                            'volume_price' => $pricing->volume_price,
                            'gw_price' => $pricing->gw_price,
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        }

        return $index;
    }


    /** @return \Illuminate\Support\Collection<int, MasterGudangChina> */
    private function gudangOptionsForForm(?Barang $barang = null): \Illuminate\Support\Collection
    {
        $list = MasterGudangChina::active()->orderBy('name')->get(['id', 'name', 'address']);

        if ($barang?->gudang_china_id && ! $list->contains('id', $barang->gudang_china_id)) {
            $current = MasterGudangChina::query()->find($barang->gudang_china_id, ['id', 'name', 'address']);
            if ($current) {
                return $list->push($current)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
            }
        }

        return $list;
    }

    private function mapAdditionalPricesForForm($rows): array
    {
        return $rows->map(static fn (BarangAdditionalPrice $row): array => [
            'id' => (int) $row->id,
            'name' => (string) ($row->name ?? ''),
            'price' => (float) $row->price,
        ])->values()->all();
    }

    private function mapProductsForForm($products): array
    {
        $ordered = BarangProduct::orderedForForm($products);
        $parentIdsWithSubs = $ordered
            ->filter(static fn (BarangProduct $product): bool => $product->parent_id !== null)
            ->pluck('parent_id')
            ->unique()
            ->flip();

        return $ordered
            ->map(function (BarangProduct $product) use ($parentIdsWithSubs): array {
                $data = array_merge($product->toArray(), [
                    'photo_url' => $product->photoUrl(),
                    'is_sub_item' => $product->isSubItem(),
                ]);

                if (! $product->isSubItem() && isset($parentIdsWithSubs[$product->id])) {
                    $data['photo_path'] = null;
                    $data['photo_url'] = null;
                    $data['has_photo'] = false;
                }

                return $data;
            })
            ->values()
            ->all();
    }

    private function markBarangApproved(array &$data): void
    {
        $data['approval_status'] = Barang::APPROVAL_APPROVED;
        $data['approved_by'] = auth()->id();
        $data['approved_at'] = now();
        $data['rejection_note'] = null;
    }

    private function markingCostFieldsEmpty(Request $request): bool
    {
        foreach (['cost_price_per_cbm', 'cost_weight_price'] as $field) {
            $value = $request->input($field);
            if ($value !== null && $value !== '' && (float) $value > 0) {
                return false;
            }
        }

        return true;
    }

    private function markingFilterValues(Request $request): array
    {
        $raw = $request->input('marking');
        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    private function markingLookupForBarangs(EloquentCollection $barangs): Collection
    {
        $codes = $barangs->pluck('marking')
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        $codeCol = Marking::codeColumn();
        if ($codeCol === null) {
            return collect();
        }

        return Marking::query()
            ->with([
                'sales:id,name',
                'branch:id,code,name',
            ])
            ->whereIn($codeCol, $codes)
            ->get()
            ->keyBy(static fn (Marking $m) => $m->displayCode());
    }

    private const GOOD_RECEIPT_MAX_KB = 10240;

    private const PRODUCT_PHOTO_MAX_KB = 10240;

    private function mergeTerimaBarangUploads(Request $request, array &$data): void
    {
        if ($request->hasFile('good_receipt_file')) {
            $data['good_receipt_path'] = $this->storeGoodReceiptFile($request->file('good_receipt_file'));
        }

        if ($request->hasFile('tracking_receipt_file')) {
            $data['tracking_receipt_path'] = $request->file('tracking_receipt_file')->store('barang-receipts', 'public');
        }

        unset($data['good_receipt_file'], $data['tracking_receipt_file']);
    }

    private function storeGoodReceiptFile(\Illuminate\Http\UploadedFile $file): string
    {
        return UploadedImageCompressor::storeForDeliveryTrip($file, 'barang-receipts', 'public');
    }

    private function storeProductPhotoFile(\Illuminate\Http\UploadedFile $file, string $directory): string
    {
        return UploadedImageCompressor::storeForDeliveryTrip($file, $directory, 'public');
    }

    private function normalizeBarangRequest(Request $request, bool $isUpdate = false): void
    {
        $numericFields = [
            'ctns',
            'pcs',
            'price',
            'insurance_value',
            'insurance_rate',
            'insurance_nominal_standar',
            'rate',
            'weight',
            'price_per_cbm',
            'weight_price',
            'cbm_threshold',
        ];

        foreach ($numericFields as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);
            if ($value === null || $value === '') {
                $request->merge([$field => null]);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalized = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
            $request->merge([$field => $normalized === '' || $normalized === '-' ? null : $normalized]);
        }

        $this->normalizeAdditionalPricesRequest($request);

        $dimensions = $request->input('dimensions');
        if (!is_array($dimensions)) {
            return;
        }

        $dimensionNumericFields = [
            'qty_ctn',
            'panjang',
            'lebar',
            'tinggi',
            'ctn_count',
            'qty',
            'gw_per_ctn',
            'gross_weight',
        ];

        foreach ($dimensions as $index => $dimension) {
            if (!is_array($dimension)) {
                continue;
            }

            foreach ($dimensionNumericFields as $field) {
                if (array_key_exists($field, $dimension) && $dimension[$field] === '') {
                    $dimensions[$index][$field] = null;
                }
            }
        }

        $request->merge(['dimensions' => $dimensions]);
    }

    private function normalizeMarkingMatchKey(mixed $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? '');

        return mb_strtolower($normalized);
    }

    private function originalPackingListDownloadUrl(Barang $barang): ?string
    {
        if (! $barang->invoice_file || ! Storage::disk('public')->exists($barang->invoice_file)) {
            return null;
        }

        return route('barang.original-pl.download', $barang);
    }

    private function parseNoResiInput(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $value),
                static fn (string $item) => $item !== '',
            ));
        }

        if (!is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '[]') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $decoded),
                static fn (string $item) => $item !== '',
            ));
        }

        return [$trimmed];
    }

    private function pickMarkingPricingRow(Marking $marking, string $cbmType): ?MarkingPricing
    {
        $cached = request()->attributes->get('resolved_marking_pricings');
        $pricings = $cached instanceof Collection ? $cached : $marking->pricings()
            ->with('pricingItem:id,name')
            ->orderBy('id')
            ->get();

        if ($pricings->isEmpty()) {
            return null;
        }

        if ($cbmType !== '') {
            $exact = $pricings->first(
                fn (MarkingPricing $row) => $this->normalizeMarkingMatchKey($row->pricingItem?->name) === $cbmType
            );
            if ($exact !== null) {
                return $exact;
            }

            $partial = $pricings->first(function (MarkingPricing $row) use ($cbmType) {
                $name = $this->normalizeMarkingMatchKey($row->pricingItem?->name);

                return str_contains($name, $cbmType) || str_contains($cbmType, $name);
            });
            if ($partial !== null) {
                return $partial;
            }
        }

        $lartas = $pricings->first(
            fn (MarkingPricing $row) => str_contains($this->normalizeMarkingMatchKey($row->pricingItem?->name), 'lartas')
        );
        if ($lartas !== null) {
            return $lartas;
        }

        $umum = $pricings->first(
            fn (MarkingPricing $row) => str_contains($this->normalizeMarkingMatchKey($row->pricingItem?->name), 'umum')
        );

        return $umum ?? $pricings->first();
    }

    private function prepareBarangUpdate(Request $request, Barang $barang): array
    {
        $old = $this->barangAuditSnapshot($barang);
        ProductsFormPayload::mergeIntoRequest($request);
        ProductsFormPayload::assertCompleteForExistingBarang($request, (int) $barang->products()->count());
        $this->stripEmptyFormRows($request);
        $this->stripEmptyProductPhotoFields($request);
        $this->normalizeBarangRequest($request, isUpdate: true);
        $this->applyDailyRateToRequest($request, isUpdate: true);
        MarkingBarangPricing::applyToRequest($request, $barang);
        $this->mergeWarehouseOriginForValidation($request, $barang);

        $data = $request->validate(
            array_merge($this->barangUpdateRules($barang), $this->productPhotoValidationRules($request)),
            $this->barangValidationMessages(),
            $this->barangValidationAttributes()
        );

        $data['no_resi'] = $this->parseNoResiInput($data['no_resi'] ?? null);

        return ['data' => $data, 'old' => $old];
    }

    private function prepareApprovedBarangReplay(Request $request, Barang $barang): array
    {
        $old = $this->barangAuditSnapshot($barang);
        $this->applyDailyRateToRequest($request, isUpdate: true);
        MarkingBarangPricing::applyToRequest($request, $barang);
        $this->mergeWarehouseOriginForValidation($request, $barang);

        $data = $request->validate(
            array_merge($this->barangUpdateRules($barang), $this->productPhotoValidationRules($request)),
            $this->barangValidationMessages(),
            $this->barangValidationAttributes(),
        );

        $data['no_resi'] = $this->parseNoResiInput($data['no_resi'] ?? null);

        return ['data' => $data, 'old' => $old];
    }

    private function productLogisticsIncomingNotEmpty(array $row, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) > 0;
        }

        return (float) ($row[$field] ?? 0) > 0;
    }

    private function productLogisticsValueChanged(array $row, BarangProduct $product, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) !== (int) $product->ctns;
        }

        return abs((float) ($row[$field] ?? 0) - (float) $product->{$field}) > 0.0001;
    }

    private function productPhotoValidationRules(Request $request): array
    {
        $rules = [];
        $products = $request->file('products');
        if (! is_array($products)) {
            return $rules;
        }

        foreach ($products as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (Barang::productUploadFileKeys() as $fileKey) {
                if (! isset($row[$fileKey])) {
                    continue;
                }

                $file = $row[$fileKey];
                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $rules["products.{$index}.{$fileKey}"] = 'file|mimes:jpg,jpeg,png,webp|max:'.self::PRODUCT_PHOTO_MAX_KB;
                }
            }
        }

        return $rules;
    }

    private function productsExportExcelUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.products.export-excel', $barang);
    }

    private function publicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        if ($normalized === '' || ! Storage::disk('public')->exists($normalized)) {
            return null;
        }

        return asset('storage/'.$normalized);
    }

    private function resolveMarking(Request $request): ?Marking
    {
        $cached = $this->resolvedMarking($request);
        if ($cached !== null) {
            return $cached;
        }

        $markingValue = $request->input('marking');
        if ($markingValue === null || $markingValue === '') {
            return null;
        }

        $marking = Marking::visibleToUser()
            ->whereBarangMarkingValue((string) $markingValue)
            ->first();

        if ($marking !== null) {
            $request->attributes->set('resolved_marking', $marking);
        }

        return $marking;
    }

    private function resolveMarkingPricingRow(Marking $marking, mixed $cbmType): ?MarkingPricing
    {
        $cached = request()->attributes->get('resolved_marking_pricings');
        $pricings = $cached instanceof Collection
            ? $cached
            : $marking->pricings()->with('pricingItem:id,name')->orderBy('id')->get();

        return MarkingPricingResolver::pickRow(
            $pricings,
            MarkingPricingResolver::normalizeKey($cbmType),
        );
    }

    private function resolvedMarking(Request $request): ?Marking
    {
        $cached = $request->attributes->get('resolved_marking');

        return $cached instanceof Marking ? $cached : null;
    }

    private function scheduleAfterDestroySideEffects(array $invoiceIds, array $snapshot): void
    {
        app()->terminating(function () use ($invoiceIds, $snapshot): void {
            ActivityLog::record('Barang deleted', null, $snapshot);

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::query()->find((int) $invoiceId);
                if (! $invoice) {
                    continue;
                }

                $invoice->recalculate();
                if (! $invoice->items()->exists()) {
                    $invoice->delete();
                }
            }
        });
    }

    private function scheduleAfterStoreSideEffects(int $barangId): void
    {
        app()->terminating(function () use ($barangId): void {
            $barang = Barang::query()->find($barangId);
            if (! $barang) {
                return;
            }

            ActivityLog::record('Barang created', $barang);
            app(BarangTrackingService::class)->syncStuffing($barang, auth()->id());
            \App\Support\SalesIncentiveService::syncForBarang($barang);
            $barang->recordInvoiceOnMasuk();
        });
    }

    private function scheduleAfterUpdateSideEffects(int $barangId, array $old, array $new): void
    {
        app()->terminating(function () use ($barangId, $old, $new): void {
            $barang = Barang::query()->find($barangId);
            if (! $barang) {
                return;
            }

            ActivityLog::record('Barang updated', $barang, $old, $new);
        });
    }

    private function sharedBarangRules(): array
    {
        return [
            'no_po' => 'nullable|string|max:50',
            'marking' => 'required|string|max:100',
            'supplier_chinese' => 'nullable|string|max:100',
            'customer_name' => 'required|string|max:150',
            'user_id' => 'nullable|exists:users,id',
            'gudang_china_id' => 'nullable|integer',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'nama_barang' => 'nullable|string|max:255',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'total_invoice' => 'nullable|string|max:100',
            'invoice_file' => ['nullable', BarangOriginalPackingList::uploadValidationRule()],
            'weight_price' => 'nullable|numeric|min:0',
            'cbm_threshold' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'packing_id' => 'nullable|exists:master_packings,id',
            'no_resi' => 'nullable|string',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function forgetUnavailableBarangColumns(array &$data): void
    {
        unset($data['nama_barang']);
    }

    private function shippingLabelPdfUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.shipping-label.pdf', $barang);
    }

    private function storeOriginalPackingListFile(\Illuminate\Http\UploadedFile $file, string $noIndex): string
    {
        return BarangOriginalPackingList::store($file, $noIndex);
    }

    private function stripEmptyFormRows(Request $request): void
    {
        if ($request->has('products') && is_array($request->input('products'))) {
            $filtered = [];
            $keptFileIndexes = [];
            $parentIndexesWithSubs = Barang::parentRowIndexesWithSubs($request->input('products'));
            foreach ($request->input('products') as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (
                    Barang::isEmptyProductInputRow($row)
                    && ! Barang::productRowHasFileUploads($request, $index)
                    && ! isset($parentIndexesWithSubs[$index])
                ) {
                    continue;
                }
                $keptFileIndexes[] = $index;
                $filtered[] = $row;
            }
            $request->merge(['products' => array_values($filtered)]);
            $this->reindexProductFileUploads($request, $keptFileIndexes);
        }

        if ($request->has('additional_prices') && is_array($request->input('additional_prices'))) {
            $filtered = [];
            foreach ($request->input('additional_prices') as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $price = $this->parseMoneyInput($row['price'] ?? 0);
                if ($name === '' && $price <= 0 && empty($row['id'])) {
                    continue;
                }
                $row['price'] = $price;
                $filtered[] = $row;
            }
            $request->merge(['additional_prices' => array_values($filtered)]);
        }
    }

    /**
     * Keep product file uploads aligned with the re-indexed products array so
     * photos are not lost/mismatched after empty rows are dropped.
     *
     * @param  list<int>  $keptFileIndexes  original product indexes, in new order
     */
    private function reindexProductFileUploads(Request $request, array $keptFileIndexes): void
    {
        $products = $request->files->get('products');
        if (! is_array($products)) {
            return;
        }

        $remapped = [];
        foreach ($keptFileIndexes as $newIndex => $originalIndex) {
            if (isset($products[$originalIndex]) && is_array($products[$originalIndex])) {
                $remapped[$newIndex] = $products[$originalIndex];
            }
        }

        $request->files->set('products', $remapped);
    }

    private function stripEmptyProductPhotoFields(Request $request): void
    {
        $products = $request->file('products');
        if (! is_array($products)) {
            return;
        }

        foreach ($products as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (Barang::productUploadFileKeys() as $fileKey) {
                if (! isset($row[$fileKey])) {
                    continue;
                }

                $file = $row[$fileKey];
                if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid()) {
                    $request->files->remove("products.{$index}.{$fileKey}");
                }
            }
        }
    }

    private function submitBarangEditRequest(Barang $barang, Request $request, array $prepared): JsonResponse
    {
        $data = $prepared['data'];

        unset(
            $data['remove_invoice_file'],
            $data['remove_good_receipt_file'],
            $data['remove_tracking_receipt_file'],
            $data['good_receipt_file'],
            $data['tracking_receipt_file'],
            $data['dimensions'],
            $data['products'],
            $data['additional_prices'],
            $data['company_loyalty_amount'],
        );

        $this->syncWarehouseOriginFromGudang($data);
        $this->ensureTanggalMasuk($data);

        $products = app(BarangEditRequestService::class)->mergeProductsForApproval(
            $prepared['data']['products'] ?? null,
            $request->input('products'),
        );

        try {
            app(BarangEditRequestService::class)->createFromUpdate(
                $barang,
                $request,
                $data,
                $products,
                is_array($prepared['data']['additional_prices'] ?? null)
                    ? $prepared['data']['additional_prices']
                    : (is_array($request->input('additional_prices')) ? $request->input('additional_prices') : null),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('barang.edit_request_submitted'),
            'redirect' => route('barang.index', ['tab' => 'pengajuan-edit']),
            'submitted_for_approval' => true,
            'data' => ['id' => $barang->id],
        ]);
    }

    private function syncBarangAdditionalPrices(Request $request, Barang $barang): void
    {
        if ($request->boolean('additional_prices_clear')) {
            $barang->additionalPrices()->delete();

            return;
        }

        if (! $request->has('additional_prices')) {
            // Blank placeholder rows are omitted by the form so existing fees stay intact.
            return;
        }

        $rows = $request->input('additional_prices', []);
        if (! is_array($rows)) {
            return;
        }

        $normalizedRows = [];
        $submittedIds = [];
        $hasContent = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $price = $this->parseMoneyInput($row['price'] ?? 0);
            $id = ! empty($row['id']) ? (int) $row['id'] : null;

            if ($id !== null) {
                $submittedIds[] = $id;
            }

            if ($name === '' && $price <= 0 && $id === null) {
                continue;
            }

            $hasContent = true;
            $normalizedRows[] = [
                'id' => $id,
                'name' => $name,
                'price' => $price,
            ];
        }

        // Only blank placeholder(s) submitted — do not wipe existing additional fees.
        if (! $hasContent && $submittedIds === []) {
            return;
        }

        $existing = $barang->additionalPrices()->get()->keyBy('id');
        $keepIds = [];
        $sort = 0;

        foreach ($normalizedRows as $row) {
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
                $keepIds[] = (int) $record->id;
            } else {
                $created = $barang->additionalPrices()->create($payload);
                $keepIds[] = (int) $created->id;
            }
        }

        if ($keepIds === []) {
            $barang->additionalPrices()->delete();

            return;
        }

        $barang->additionalPrices()->whereNotIn('id', $keepIds)->delete();
    }

    /**
     * Parse Rp / plain numeric form input into a float.
     * Plain integers / western decimals ("40000" / "40000.50") cast directly.
     * Masked IDR ("Rp 40.000" / "40.000,50") uses "." thousand + "," decimal.
     */
    private function parseMoneyInput(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return 0.0;
        }

        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return max(0.0, (float) $trimmed);
        }

        // Western decimal with 1–2 fraction digits (not an IDR thousand group of 3).
        if (
            preg_match('/^-?\d+\.\d{1,2}$/', $trimmed) === 1
            && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $trimmed) !== 1
        ) {
            return max(0.0, (float) $trimmed);
        }

        $raw = preg_replace('/[^0-9.,\-]/', '', $trimmed) ?? '';
        if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
            return 0.0;
        }

        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);

        return max(0.0, (float) $raw);
    }

    private function normalizeAdditionalPricesRequest(Request $request): void
    {
        if (! $request->has('additional_prices') || ! is_array($request->input('additional_prices'))) {
            return;
        }

        $normalized = [];
        foreach ($request->input('additional_prices') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['price'] = $this->parseMoneyInput($row['price'] ?? 0);
            if (array_key_exists('name', $row)) {
                $row['name'] = trim((string) $row['name']);
            }
            $normalized[] = $row;
        }

        $request->merge(['additional_prices' => array_values($normalized)]);
    }

    private function syncBarangProducts(Request $request, Barang $barang): void
    {
        $barang->syncProductsFromRequest($request);
    }

    private function syncTerimaBarangUploads(Request $request, Barang $barang, array &$data): void
    {
        if ($request->hasFile('good_receipt_file')) {
            if ($barang->good_receipt_path) {
                Storage::disk('public')->delete($barang->good_receipt_path);
            }
            $data['good_receipt_path'] = $this->storeGoodReceiptFile($request->file('good_receipt_file'));
        } elseif ($request->boolean('remove_good_receipt_file')) {
            if ($barang->good_receipt_path) {
                Storage::disk('public')->delete($barang->good_receipt_path);
            }
            $data['good_receipt_path'] = null;
        }

        if ($request->hasFile('tracking_receipt_file')) {
            if ($barang->tracking_receipt_path) {
                Storage::disk('public')->delete($barang->tracking_receipt_path);
            }
            $data['tracking_receipt_path'] = $request->file('tracking_receipt_file')->store('barang-receipts', 'public');
        } elseif ($request->boolean('remove_tracking_receipt_file')) {
            if ($barang->tracking_receipt_path) {
                Storage::disk('public')->delete($barang->tracking_receipt_path);
            }
            $data['tracking_receipt_path'] = null;
        }
    }

    private function mergeWarehouseOriginForValidation(Request $request, ?Barang $barang = null): void
    {
        $gudangId = $request->input('gudang_china_id');

        if ($gudangId !== null && $gudangId !== '') {
            $name = MasterGudangChina::query()
                ->where('id', $gudangId)
                ->value('name');

            if (is_string($name) && trim($name) !== '') {
                $request->merge(['warehouse_origin' => trim($name)]);

                return;
            }
        }

        if ($barang === null) {
            return;
        }

        $submittedOrigin = trim((string) ($request->input('warehouse_origin') ?? ''));
        if ($submittedOrigin !== '') {
            return;
        }

        $existingOrigin = trim((string) ($barang->warehouse_origin ?? ''));
        if ($existingOrigin !== '') {
            $request->merge(['warehouse_origin' => $existingOrigin]);

            return;
        }

        if ($barang->gudang_china_id) {
            $name = MasterGudangChina::query()
                ->where('id', $barang->gudang_china_id)
                ->value('name');

            if (is_string($name) && trim($name) !== '') {
                $request->merge(['warehouse_origin' => trim($name)]);
            }
        }
    }

    private function syncWarehouseOriginFromGudang(array &$data): void
    {
        if (empty($data['gudang_china_id'])) {
            return;
        }

        $name = MasterGudangChina::query()
            ->where('id', $data['gudang_china_id'])
            ->value('name');

        if (is_string($name) && trim($name) !== '') {
            $data['warehouse_origin'] = trim($name);
        }
    }

    private function terimaBarangPdfUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.terima-barang.pdf', $barang);
    }

}
