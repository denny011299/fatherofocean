<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Container;
use App\Models\Marking;
use App\Models\MasterBranch;
use App\Models\MasterContainerType;
use App\Models\MasterDepartment;
use App\Models\MasterGudangChina;
use App\Models\MasterRoute;
use App\Models\Port;
use App\Support\CbmFormat;
use App\Support\DatePresetFilter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ContainerController extends Controller
{
    public function index(Request $request): View
    {
        $ports = Port::active()->orderBy('name')->get();
        $containerTypes = MasterContainerType::active()->orderBy('code')->get();
        $branches = MasterBranch::active()->orderBy('code')->get();
        $departments = MasterDepartment::active()->orderBy('name')->get();
        $routes = MasterRoute::active()->orderBy('name')->get();
        $gudangList = MasterGudangChina::active()->orderBy('name')->get();
        $markings = Marking::forDropdown()->visibleToUser()->get(Marking::dropdownSelectColumns());

        return view('container.index', compact(
            'ports', 'containerTypes',
            'branches', 'departments', 'routes', 'gudangList', 'markings'
        ));
    }

    public function data(): JsonResponse
    {
        $containers = Container::with('port:id,name,city', 'barangs', 'creator:id,name', 'gudangChina:id,name')
            ->latest()
            ->get()
            ->map(fn (Container $c) => [
                'id'                  => $c->id,
                'gudang_china_name'   => $c->gudangChina?->name ?? $c->alamat_gudang_china,
                'container_no'        => $c->container_no,
                'no_bl'               => $c->no_bl,
                'partai'              => $c->partai,
                'code'                => $c->partai,
                'item_count'          => $c->barangs->count(),
                'total_ctns'          => $c->total_ctns,
                'used_volume'         => $c->used_volume,
                'max_volume'          => $c->max_volume,
                'creator_name'        => $c->creator?->name ?? '-',
                'load_date'           => $c->load_date?->format('d M Y'),
                'etd'                 => $c->etd?->format('d M Y'),
                'etd_date'            => $c->etd?->toDateString(),
                'eta'                 => $c->eta?->format('d M Y'),
                'can_delete'          => auth()->user()?->hasRole('super_admin') === true,
                'can_edit'            => $c->canBeEdited()
                    || auth()->user()?->hasAnyRole(['super_admin', 'supervisor']),
                'capacity_percent'    => $c->capacity_percent,
                'capacity_level'      => $c->capacity_level,
                'status'              => $c->status ?? 'open',
            ]);

        return response()->json($containers);
    }

    public function createPage(): View
    {
        $ports = Port::active()->orderBy('name')->get();
        $containerTypes = MasterContainerType::active()->orderBy('code')->get();
        $branches = MasterBranch::active()->orderBy('code')->get();
        $departments = MasterDepartment::active()->orderBy('name')->get();
        $routes = MasterRoute::active()->orderBy('name')->get();
        $gudangList = MasterGudangChina::active()->orderBy('name')->get();
        $markings = Marking::forDropdown()->visibleToUser()->get(Marking::dropdownSelectColumns());

        return view('container.create', compact(
            'ports', 'containerTypes',
            'branches', 'departments', 'routes', 'gudangList', 'markings'
        ));
    }

    public function markingPreviewDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marking_id' => 'nullable|exists:markings,id',
            'max_volume' => 'required|numeric|min:0',
            'staged_cbm' => 'nullable|numeric|min:0',
            'date_preset' => 'nullable|string',
            'tanggal_from' => 'nullable|date',
            'tanggal_to' => 'nullable|date',
        ]);

        $marking = isset($data['marking_id'])
            ? Marking::visibleToUser()->with('customer:id,name')->findOrFail($data['marking_id'])
            : null;

        if ($marking === null && ! $this->requestHasInboundDateFilter($request)) {
            return response()->json($this->emptyMarkingPreviewPayload());
        }

        $barangs = $this->eligibleBarangsForEstimation($marking, $request);
        $maxVolume = (float) $data['max_volume'];
        $stagedCbm = (float) ($data['staged_cbm'] ?? 0);
        $remainingVolume = max(0, $maxVolume - $stagedCbm);

        return response()->json($this->buildMarkingPreviewPayload($marking, $barangs, $remainingVolume));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('create container'), 403);

        $data = $request->validate([
            'partai' => 'required|string|max:30|unique:containers,partai',
            'container_no' => 'nullable|string|max:30',
            'no_bl' => 'nullable|string|max:30',
            'type' => 'required|string|exists:master_container_types,code',
            'branch' => 'nullable|string|max:50',
            'gudang_china_id' => 'nullable|exists:master_gudang_china,id',
            'marking_id' => 'nullable|exists:markings,id',
            'load_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_date' => 'nullable|date',
            'gate_pass_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'is_join' => 'nullable|boolean',
            'route' => 'nullable|string|max:100',
            'department_item' => 'nullable|string|max:100',
            'length_type' => 'nullable|string|max:20',
            'port_id' => 'nullable|exists:ports,id',
            'barang_ids' => 'nullable|array',
            'barang_ids.*' => 'integer|exists:barangs,id',
        ]);

        $barangIds = array_values(array_unique($data['barang_ids'] ?? []));
        unset($data['barang_ids']);

        $spec = MasterContainerType::where('code', $data['type'])->first();
        if ($spec) {
            $data['max_length'] = $spec->length;
            $data['max_width'] = $spec->width;
            $data['max_height'] = $spec->height;
            $data['max_volume'] = $spec->volume;
        }
        $data['is_join'] = $request->boolean('is_join');
        $data['created_by'] = auth()->id();
        $data['departure_date'] = $data['etd'] ?? null;

        $container = DB::transaction(function () use ($data, $barangIds) {
            $container = Container::create($data);

            if ($barangIds !== []) {
                $this->loadBarangsOntoContainer($container, $barangIds);
            }

            return $container;
        });

        ActivityLog::record('Container created', $container);

        $message = $barangIds === []
            ? __('messages.container_created')
            : __('messages.container_created_with_items', ['count' => count($barangIds)]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $container,
            'redirect' => route('container.detail', $container),
        ]);
    }

    public function show(Container $container): JsonResponse
    {
        $container->load('barangs', 'port', 'creator');

        return response()->json([
            'container' => $container,
            'used_volume' => $container->used_volume,
            'remaining_volume' => $container->remaining_volume,
            'total_ctns' => $container->total_ctns,
            'capacity_percent' => $container->capacity_percent,
            'capacity_level' => $container->capacity_level,
            'ready_invoicing' => $container->ready_invoicing,
        ]);
    }

    public function detail(Container $container): View
    {
        $container->load([
            'barangs.dimensions',
            'barangs.customer',
            'barangs.invoiceItems.invoice.items',
            'barangs.products' => static fn ($q) => $q->orderBy('sort_order')->select(
                'id',
                'barang_id',
                'product_name',
                'material',
                'sort_order',
            ),
            'port:id,name,city',
            'creator',
        ]);

        $barangs = $container->barangs
            ->sortBy(fn ($barang) => [$barang->marking ?? '', $barang->id])
            ->values();

        return view('container.detail', [
            'container' => $container,
            'barangs' => $barangs,
            'usedVolume' => $container->used_volume,
            'remainingVolume' => $container->remaining_volume,
            'capacityPercent' => $container->capacity_percent,
            'capacityLevel' => $container->capacity_level,
        ]);
    }

    public function loadEstimation(Container $container): View
    {
        $container->load('barangs', 'port', 'creator', 'gudangChina', 'marking');
        $markings = Marking::forDropdown()->visibleToUser()->get(Marking::dropdownSelectColumns());

        return view('container.load-estimation', [
            'container' => $container,
            'markings' => $markings,
            'usedVolume' => $container->used_volume,
            'remainingVolume' => $container->remaining_volume,
            'capacityPercent' => $container->capacity_percent,
            'capacityLevel' => $container->capacity_level,
        ]);
    }

    public function markingPreview(Request $request, Container $container): JsonResponse
    {
        $data = $request->validate([
            'marking_id' => 'nullable|exists:markings,id',
            'date_preset' => 'nullable|string',
            'tanggal_from' => 'nullable|date',
            'tanggal_to' => 'nullable|date',
        ]);

        $marking = isset($data['marking_id'])
            ? Marking::visibleToUser()->with('customer:id,name')->findOrFail($data['marking_id'])
            : null;

        if ($marking === null && ! $this->requestHasInboundDateFilter($request)) {
            return response()->json($this->emptyMarkingPreviewPayload());
        }

        $barangs = $this->eligibleBarangsForEstimation($marking, $request);

        return response()->json($this->buildMarkingPreviewPayload($marking, $barangs, $container->remaining_volume));
    }

    public function loadBatch(Request $request, Container $container): JsonResponse
    {
        abort_unless(auth()->user()?->can('load barang'), 403);

        $data = $request->validate([
            'barang_ids' => 'required|array|min:1',
            'barang_ids.*' => 'integer|exists:barangs,id',
        ]);

        $barangIds = array_values(array_unique($data['barang_ids']));

        try {
            DB::transaction(function () use ($container, $barangIds) {
                $this->loadBarangsOntoContainer($container, $barangIds);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        $container->load('barangs');

        return response()->json([
            'success' => true,
            'message' => __('messages.items_loaded', ['count' => count($barangIds)]),
            'used_volume' => $container->used_volume,
            'remaining_volume' => $container->remaining_volume,
            'max_volume' => (float) $container->max_volume,
            'total_ctns' => $container->total_ctns,
            'capacity_percent' => $container->capacity_percent,
            'capacity_level' => $container->capacity_level,
            'total_items' => $container->barangs->count(),
        ]);
    }

    public function update(Request $request, Container $container): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit container'), 403);

        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') === true;

        if (! $container->canBeEdited() && ! $user?->hasAnyRole(['super_admin', 'supervisor'])) {
            return response()->json([
                'success' => false,
                'message' => __('messages.container_cannot_edit_departed'),
            ], 422);
        }

        $old = $container->toArray();

        $rules = [
            'container_no' => 'nullable|string|max:30',
            'no_bl' => 'nullable|string|max:30',
            'branch' => 'nullable|string|max:50',
            'gudang_china_id' => 'nullable|exists:master_gudang_china,id',
            'marking_id' => 'nullable|exists:markings,id',
            'load_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_date' => 'nullable|date',
            'gate_pass_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'arrival_date_actual' => 'nullable|date',
            'is_join' => 'nullable|boolean',
            'route' => 'nullable|string|max:100',
            'department_item' => 'nullable|string|max:100',
            'port_id' => 'nullable|exists:ports,id',
            'status' => 'nullable|string|in:open,loading,shipped,arrived,unloading,closed',
        ];

        if ($isSuperAdmin) {
            $rules['type'] = 'nullable|string|exists:master_container_types,code';
        }

        $data = $request->validate($rules);

        if ($isSuperAdmin && isset($data['type'])) {
            $spec = MasterContainerType::where('code', $data['type'])->first();
            if ($spec) {
                $data['max_length'] = $spec->length;
                $data['max_width'] = $spec->width;
                $data['max_height'] = $spec->height;
                $data['max_volume'] = $spec->volume;
            }
        }

        if ($request->has('is_join')) {
            $data['is_join'] = $request->boolean('is_join');
        }
        if (isset($data['etd'])) {
            $data['departure_date'] = $data['etd'];
        }
        $container->update($data);
        ActivityLog::record('Container updated', $container, $old, $container->fresh()->toArray());

        return response()->json(['success' => true, 'message' => __('messages.container_updated')]);
    }

    public function loadBarang(Request $request, Container $container): JsonResponse
    {
        abort_unless(auth()->user()?->can('load barang'), 403);

        $request->validate([
            'no_index' => 'required|string',
        ]);

        $barang = Barang::where('no_index', $request->no_index)->first();

        if (!$barang) {
            return response()->json(['success' => false, 'message' => __('messages.barang_not_found_by_index')], 404);
        }

        if ($barang->containers()->where('containers.id', '!=', $container->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Barang sudah dimuat di container lain: ' . $barang->no_index,
            ], 422);
        }

        if (! $barang->isContainerLoadable()) {
            return response()->json([
                'success' => false,
                'message' => __('messages.barang_load_requires_stuffing', ['items' => $barang->no_index]),
            ], 422);
        }

        if ($container->barangs()->where('barang_id', $barang->id)->exists()) {
            return response()->json(['success' => false, 'message' => __('messages.barang_already_in_container')], 422);
        }

        $barangCbm = (float) $barang->total_cbm;

        if (!$container->canFit($barangCbm)) {
            $remaining = $container->remaining_volume;
            return response()->json([
                'success' => false,
                'message' => __('messages.capacity_exceeded', [
                    'remaining' => CbmFormat::format($remaining),
                    'needed' => CbmFormat::format($barangCbm),
                ]),
                'remaining' => $remaining,
                'needed' => $barangCbm,
            ], 422);
        }

        DB::transaction(function () use ($container, $barang) {
            $this->attachBarangToContainer($container, $barang);
        });

        ActivityLog::record('Barang loaded to container', $barang, null, ['container_id' => $container->id]);

        $container->load('barangs');

        return response()->json([
            'success' => true,
            'message' => __('messages.item_loaded', ['no_index' => $barang->no_index]),
            'barang' => $barang,
            'used_volume' => $container->used_volume,
            'remaining_volume' => $container->remaining_volume,
            'max_volume' => (float) $container->max_volume,
            'total_ctns' => $container->total_ctns,
            'capacity_percent' => $container->capacity_percent,
            'capacity_level' => $container->capacity_level,
            'total_items' => $container->barangs->count(),
        ]);
    }

    public function destroy(Container $container): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        ActivityLog::record('Container deleted', $container);

        DB::transaction(function () use ($container) {
            foreach ($container->barangs as $barang) {
                $barang->update(['status' => Barang::STATUS_STUFFING]);
                \App\Support\SalesIncentiveService::syncForBarang($barang->fresh());
            }
            $container->barangs()->detach();
            $container->delete();
        });

        return response()->json(['success' => true, 'message' => __('messages.container_deleted')]);
    }

    public function unloadBarang(Container $container, Barang $barang): JsonResponse
    {
        $user = auth()->user();
        abort_unless(
            $user?->can('unload barang')
            || $user?->hasAnyRole(['super_admin', 'supervisor']),
            403,
        );

        if ($container->hasDeparted() && ! $user?->hasAnyRole(['super_admin', 'supervisor'])) {
            return response()->json([
                'success' => false,
                'message' => __('messages.container_cannot_unload_departed'),
            ], 422);
        }

        if (! $container->barangs()->whereKey($barang->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('messages.barang_not_in_container'),
            ], 404);
        }

        DB::transaction(function () use ($container, $barang) {
            $container->barangs()->detach($barang->id);
            $barang->update(['status' => Barang::STATUS_STUFFING]);
            \App\Support\SalesIncentiveService::syncForBarang($barang->fresh());
        });

        ActivityLog::record('Barang unloaded from container', $barang, null, ['container_id' => $container->id]);

        $container->load('barangs');

        return response()->json([
            'success' => true,
            'message' => __('messages.item_unloaded', ['no_index' => $barang->no_index]),
            'item_count' => $container->barangs->count(),
            'total_ctns' => $container->total_ctns,
            'used_volume' => $container->used_volume,
            'remaining_volume' => $container->remaining_volume,
            'capacity_percent' => $container->capacity_percent,
            'capacity_level' => $container->capacity_level,
        ]);
    }

    private function eligibleBarangsForEstimation(?Marking $marking, ?Request $request = null): Collection
    {
        $query = Barang::query()
            ->with([
                'customer:id,name',
                'packing:id,name',
                'products' => static fn ($q) => $q->orderBy('sort_order')->select(
                    'id',
                    'barang_id',
                    'product_name',
                    'material',
                    'sort_order',
                ),
            ])
            ->awaitingContainerLoad();

        if ($marking !== null) {
            $code = trim($marking->displayCode());
            if ($code === '') {
                return collect();
            }
            $query->where('marking', $code);
        }

        Marking::restrictBarangsQuery($query);

        if ($request !== null) {
            $query->filterByTanggalMasukPreset(
                $request->input('date_preset'),
                $request->input('tanggal_from'),
                $request->input('tanggal_to'),
            );
        }

        return $query->orderByDesc('tanggal_masuk')->orderBy('no_index')->get();
    }

    private function requestHasInboundDateFilter(Request $request): bool
    {
        $preset = DatePresetFilter::normalizePreset($request->input('date_preset'));
        if ($preset !== '') {
            return true;
        }

        return $request->filled('tanggal_from') || $request->filled('tanggal_to');
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarkingPreviewPayload(): array
    {
        return [
            'marking' => null,
            'barangs' => [],
            'totals' => ['ctns' => 0, 'total_cbm' => 0.0, 'total_gw' => 0.0],
            'fits_remaining' => true,
            'remaining_after' => 0.0,
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    private function buildMarkingPreviewPayload(?Marking $marking, Collection $barangs, float $remainingVolume): array
    {
        $totals = [
            'ctns' => (int) $barangs->sum('ctns'),
            'total_cbm' => CbmFormat::truncate((float) $barangs->sum('total_cbm')),
            'total_gw' => round((float) $barangs->sum('total_gross_weight'), 2),
        ];

        $remainingAfter = $remainingVolume - $totals['total_cbm'];

        return [
            'marking' => $marking !== null ? [
                'id' => $marking->id,
                'code' => $marking->displayCode(),
                'description' => $marking->description,
                'customer_name' => $marking->customer_name ?? $marking->customer?->name,
            ] : null,
            'barangs' => $barangs->map(fn (Barang $barang) => $this->mapBarangForMarkingPreview($barang))->values(),
            'totals' => $totals,
            'fits_remaining' => $totals['total_cbm'] <= $remainingVolume,
            'remaining_after' => CbmFormat::truncate(max(0, $remainingAfter)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBarangForMarkingPreview(Barang $barang): array
    {
        return [
            'id' => $barang->id,
            'no_index' => $barang->no_index,
            'no_po' => $barang->no_po,
            'marking' => $barang->marking,
            'customer_name' => $barang->customer_name ?? $barang->customer?->name,
            'tanggal_masuk_display' => $barang->tanggal_masuk
                ? $barang->tanggal_masuk->locale(app()->getLocale())->translatedFormat('d M Y')
                : null,
            'barang_summary' => $barang->itemsSummary(),
            'packing_name' => $barang->packing?->name,
            'ctns' => (int) $barang->ctns,
            'total_cbm' => (float) $barang->total_cbm,
            'total_gw' => (float) $barang->total_gross_weight,
            'status' => $barang->status,
        ];
    }

    /**
     * @param  list<int>  $barangIds
     */
    private function loadBarangsOntoContainer(Container $container, array $barangIds): void
    {
        $barangs = Barang::with('customer:id,name')->whereIn('id', $barangIds)->get();

        if ($barangs->count() !== count($barangIds)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'barang_ids' => __('messages.some_barang_not_found'),
            ]);
        }

        $alreadyLoaded = $container->barangs()->whereIn('barang_id', $barangIds)->pluck('barang_id');
        if ($alreadyLoaded->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'barang_ids' => __('messages.some_barang_already_in_container'),
            ]);
        }

        $inOtherContainer = Barang::query()
            ->whereIn('id', $barangIds)
            ->whereHas('containers', fn ($query) => $query->where('containers.id', '!=', $container->id))
            ->pluck('no_index');

        if ($inOtherContainer->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'barang_ids' => 'Barang sudah dimuat di container lain: ' . $inOtherContainer->implode(', '),
            ]);
        }

        $notLoadable = $barangs->filter(static fn (Barang $barang): bool => ! $barang->isContainerLoadable());
        if ($notLoadable->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'barang_ids' => __('messages.barang_load_requires_stuffing', [
                    'items' => $notLoadable->pluck('no_index')->implode(', '),
                ]),
            ]);
        }

        $totalCbm = CbmFormat::truncate((float) $barangs->sum('total_cbm'));
        if (!$container->canFit($totalCbm)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'barang_ids' => __('messages.capacity_exceeded_bulk', [
                    'remaining' => CbmFormat::format($container->remaining_volume),
                    'needed' => CbmFormat::format($totalCbm),
                ]),
            ]);
        }

        foreach ($barangs as $barang) {
            $this->attachBarangToContainer($container, $barang);
            ActivityLog::record('Barang loaded to container', $barang, null, ['container_id' => $container->id]);
        }
    }

    private function attachBarangToContainer(Container $container, Barang $barang): void
    {
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => auth()->id(),
        ]);
        $barang->update(['status' => Barang::STATUS_STUFFING]);

        $barang->syncStuffingTracking(auth()->id());
        $barang->syncContainerLoadedTracking($container, auth()->id());
        $container->syncLoadingTracking(null, null, auth()->id());
        $barang = $barang->fresh(['containers']);
        \App\Support\InvoiceConsolidation::consolidateDraftsInContainer($container->fresh(['barangs.containers']), $barang);
        \App\Support\SalesIncentiveService::syncForBarang($barang);
    }
}
