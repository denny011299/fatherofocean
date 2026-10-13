<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Container;
use App\Models\MasterBranch;
use App\Models\MasterContainerType;
use App\Models\MasterDepartment;
use App\Models\MasterGudangChina;
use App\Models\MasterRoute;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ContainerController extends Controller
{
    public function index(Request $request): View
    {
        $vessels = Vessel::active()->orderBy('name')->get();
        $containerTypes = MasterContainerType::active()->orderBy('code')->get();
        $branches = MasterBranch::active()->orderBy('code')->get();
        $departments = MasterDepartment::active()->orderBy('name')->get();
        $routes = MasterRoute::active()->orderBy('name')->get();
        $gudangList = MasterGudangChina::active()->orderBy('name')->get();

        return view('container.index', compact(
            'vessels', 'containerTypes',
            'branches', 'departments', 'routes', 'gudangList'
        ));
    }

    public function data(): JsonResponse
    {
        $containers = Container::with('vessel:id,name', 'barangs', 'creator:id,name', 'gudangChina:id,name')
            ->latest()
            ->get()
            ->map(fn (Container $c) => [
                'id'                  => $c->id,
                'gudang_china_name'   => $c->gudangChina?->name ?? $c->alamat_gudang_china,
                'container_no'        => $c->container_no,
                'seal_no'             => $c->seal_no,
                'code'                => $c->code,
                'item_count'          => $c->barangs->count(),
                'total_ctns'          => $c->total_ctns,
                'creator_name'        => $c->creator?->name ?? '-',
                'load_date'           => $c->load_date?->format('d M Y'),
                'etd'                 => $c->etd?->format('d M Y'),
                'eta'                 => $c->eta?->format('d M Y'),
            ]);

        return response()->json($containers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:30|unique:containers',
            'container_no' => 'nullable|string|max:30',
            'seal_no' => 'nullable|string|max:30',
            'type' => 'required|string|exists:master_container_types,code',
            'branch' => 'nullable|string|max:50',
            'gudang_china_id' => 'nullable|exists:master_gudang_china,id',
            'load_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_date' => 'nullable|date',
            'gate_pass_date' => 'nullable|date',
            'arrival_date' => 'nullable|date',
            'is_join' => 'nullable|boolean',
            'route' => 'nullable|string|max:100',
            'department_item' => 'nullable|string|max:100',
            'length_type' => 'nullable|string|max:20',
            'vessel_id' => 'nullable|exists:vessels,id',
        ]);

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

        $container = Container::create($data);
        ActivityLog::record('Container created', $container);

        return response()->json(['success' => true, 'message' => 'Container berhasil dibuat.', 'data' => $container]);
    }

    public function show(Container $container): JsonResponse
    {
        $container->load('barangs', 'vessel', 'creator');

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
        $container->load('barangs.dimensions', 'barangs.customer', 'barangs.invoiceItems.invoice', 'vessel', 'creator');

        return view('container.detail', [
            'container' => $container,
            'usedVolume' => $container->used_volume,
            'remainingVolume' => $container->remaining_volume,
            'capacityPercent' => $container->capacity_percent,
            'capacityLevel' => $container->capacity_level,
        ]);
    }

    public function update(Request $request, Container $container): JsonResponse
    {
        $old = $container->toArray();

        $data = $request->validate([
            'container_no' => 'nullable|string|max:30',
            'seal_no' => 'nullable|string|max:30',
            'branch' => 'nullable|string|max:50',
            'gudang_china_id' => 'nullable|exists:master_gudang_china,id',
            'load_date' => 'nullable|date',
            'etd' => 'nullable|date',
            'eta' => 'nullable|date',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_date' => 'nullable|date',
            'gate_pass_date' => 'nullable|date',
            'arrival_date_actual' => 'nullable|date',
            'is_join' => 'nullable|boolean',
            'route' => 'nullable|string|max:100',
            'department_item' => 'nullable|string|max:100',
            'vessel_id' => 'nullable|exists:vessels,id',
            'status' => 'nullable|string|in:open,loading,shipped,arrived,closed',
        ]);

        if ($request->has('is_join')) {
            $data['is_join'] = $request->boolean('is_join');
        }
        if (isset($data['etd'])) {
            $data['departure_date'] = $data['etd'];
        }
        $container->update($data);
        ActivityLog::record('Container updated', $container, $old, $container->fresh()->toArray());

        return response()->json(['success' => true, 'message' => 'Container berhasil diperbarui.']);
    }

    public function loadBarang(Request $request, Container $container): JsonResponse
    {
        $request->validate([
            'no_index' => 'required|string',
        ]);

        $barang = Barang::where('no_index', $request->no_index)->first();

        if (!$barang) {
            return response()->json(['success' => false, 'message' => 'Barang dengan No. Index tersebut tidak ditemukan.'], 404);
        }

        if ($container->barangs()->where('barang_id', $barang->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Barang sudah ada di dalam container ini.'], 422);
        }

        $barangCbm = (float) $barang->total_cbm;

        if (!$container->canFit($barangCbm)) {
            $remaining = $container->remaining_volume;
            return response()->json([
                'success' => false,
                'message' => "TIDAK MUAT! Sisa kapasitas: " . number_format($remaining, 4) . " CBM, barang butuh: " . number_format($barangCbm, 4) . " CBM.",
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
            'message' => "Barang {$barang->no_index} berhasil dimuat.",
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
        DB::transaction(function () use ($container) {
            foreach ($container->barangs as $barang) {
                $barang->update(['status' => 'inbound']);
            }
            $container->barangs()->detach();
            $container->delete();
        });

        ActivityLog::record('Container deleted', $container);

        return response()->json(['success' => true, 'message' => 'Container berhasil dihapus.']);
    }

    public function unloadBarang(Container $container, Barang $barang): JsonResponse
    {
        DB::transaction(function () use ($container, $barang) {
            $container->barangs()->detach($barang->id);
            $barang->update(['status' => 'inbound']);
        });

        ActivityLog::record('Barang unloaded from container', $barang, null, ['container_id' => $container->id]);

        $container->load('barangs');

        return response()->json([
            'success' => true,
            'message' => "Barang {$barang->no_index} berhasil diturunkan.",
            'used_volume' => $container->used_volume,
            'remaining_volume' => $container->remaining_volume,
            'capacity_percent' => $container->capacity_percent,
            'capacity_level' => $container->capacity_level,
        ]);
    }

    private function eligibleBarangsForMarking(Marking $marking, Container $container): Collection
    {
        return Barang::with('customer:id,name')
            ->where('marking', $marking->code)
            ->where('status', 'inbound')
            ->whereDoesntHave('containers')
            ->orderBy('no_index')
            ->get();
    }

    private function attachBarangToContainer(Container $container, Barang $barang): void
    {
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => auth()->id(),
        ]);
        $barang->update(['status' => 'loaded']);
    }
}
