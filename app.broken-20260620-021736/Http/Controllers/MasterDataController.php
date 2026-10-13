<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\MasterBranch;
use App\Models\MasterCbmType;
use App\Models\MasterClassification;
use App\Models\MasterContainerType;
use App\Models\MasterCountUnit;
use App\Models\MasterDepartment;
use App\Models\MasterPackagingType;
use App\Models\MasterPricingItem;
use App\Models\MasterRoute;
use App\Models\Port;
use App\Models\Province;
use App\Models\Vessel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MasterDataController extends Controller
{
    private const MASTER_MAP = [
        'branches'        => MasterBranch::class,
        'departments'     => MasterDepartment::class,
        'routes'          => MasterRoute::class,
        'countries'       => Country::class,
        'provinces'       => Province::class,
        'cities'          => City::class,
        'districts'       => District::class,
        'container-types' => MasterContainerType::class,
        'packaging-types' => MasterPackagingType::class,
        'cbm-types'       => MasterCbmType::class,
        'classifications' => MasterClassification::class,
        'count-units'     => MasterCountUnit::class,
        'vessels'         => Vessel::class,
        'ports'           => Port::class,
        'pricing-items'   => MasterPricingItem::class,
    ];

    private const TYPE_META = [
        'branches'        => ['title' => 'Cabang / Branch',     'group' => 'Operasional',    'icon' => 'bi-building',       'order' => 'code'],
        'departments'     => ['title' => 'Departemen',          'group' => 'Operasional',    'icon' => 'bi-grid-3x3-gap',   'order' => 'name'],
        'routes'          => ['title' => 'Rute Pengiriman',     'group' => 'Operasional',    'icon' => 'bi-signpost-2',      'order' => 'name'],
        'countries'       => ['title' => 'Negara',              'group' => 'Lokasi',         'icon' => 'bi-globe',           'order' => 'name'],
        'provinces'       => ['title' => 'Provinsi',            'group' => 'Lokasi',         'icon' => 'bi-map',             'order' => 'name'],
        'cities'          => ['title' => 'Kota / Kabupaten',    'group' => 'Lokasi',         'icon' => 'bi-geo-alt',         'order' => 'name'],
        'districts'       => ['title' => 'Kecamatan',           'group' => 'Lokasi',         'icon' => 'bi-pin-map-fill',    'order' => 'name'],
        'container-types' => ['title' => 'Tipe Kontainer',      'group' => 'Data Barang',    'icon' => 'bi-box-seam',        'order' => 'code'],
        'packaging-types' => ['title' => 'Tipe Packaging',      'group' => 'Data Barang',    'icon' => 'bi-archive',         'order' => 'name'],
        'cbm-types'       => ['title' => 'Tipe CBM',            'group' => 'Data Barang',    'icon' => 'bi-rulers',          'order' => 'name'],
        'classifications' => ['title' => 'Klasifikasi Barang',  'group' => 'Data Barang',    'icon' => 'bi-tags',            'order' => 'name'],
        'count-units'     => ['title' => 'Satuan Hitung',       'group' => 'Data Barang',    'icon' => 'bi-123',             'order' => 'name'],
        'vessels'         => ['title' => 'Kapal',               'group' => 'Mitra & Armada', 'icon' => 'bi-tsunami',         'order' => 'name'],
        'ports'           => ['title' => 'Pelabuhan',           'group' => 'Mitra & Armada', 'icon' => 'bi-pin-map',         'order' => 'name'],
        'pricing-items'   => ['title' => 'Item Pricing',         'group' => 'Operasional',    'icon' => 'bi-currency-dollar', 'order' => 'name'],
    ];

    public function page(string $type): View
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        $meta = self::TYPE_META[$type] ?? null;

        if (!$modelClass || !$meta) {
            abort(404);
        }

        $columns = $this->getColumns($type);
        $fields = $this->getFields($type);

        $parentData = [];
        if ($type === 'provinces') {
            $parentData['countries'] = Country::active()->orderBy('name')->get(['id', 'name', 'code']);
        } elseif ($type === 'cities') {
            $parentData['provinces'] = Province::active()->with('country:id,name')->orderBy('name')->get(['id', 'name', 'country_id']);
        } elseif ($type === 'districts') {
            $parentData['cities'] = City::active()->with('province:id,name')->orderBy('name')->get(['id', 'name', 'province_id']);
        }

        return view('master-data.page', [
            'type'       => $type,
            'title'      => $meta['title'],
            'group'      => $meta['group'],
            'icon'       => $meta['icon'],
            'columns'    => $columns,
            'fields'     => $fields,
            'parentData' => $parentData,
        ]);
    }

    private function getColumns(string $type): array
    {
        $cols = [];

        if ($type === 'gudang-china') {
            return [
                ['key' => 'name', 'label' => 'Nama'],
                ['key' => 'address', 'label' => 'Alamat'],
            ];
        }

        if (in_array($type, ['branches', 'container-types', 'vessels', 'ports', 'countries'])) {
            $cols[] = ['key' => 'code', 'label' => 'Kode'];
        }

        $cols[] = ['key' => 'name', 'label' => 'Nama'];

        $extra = match ($type) {
            'countries'       => [['key' => 'phone_code', 'label' => 'Kode Telepon']],
            'provinces'       => [
                ['key' => 'country_name', 'label' => 'Negara'],
                ['key' => 'code', 'label' => 'Kode'],
            ],
            'cities'          => [
                ['key' => 'province_name', 'label' => 'Provinsi'],
                ['key' => 'country_name', 'label' => 'Negara'],
                ['key' => 'type', 'label' => 'Tipe'],
            ],
            'districts'       => [
                ['key' => 'city_name', 'label' => 'Kota'],
                ['key' => 'province_name', 'label' => 'Provinsi'],
            ],
            'routes'          => [
                ['key' => 'origin', 'label' => 'Asal'],
                ['key' => 'destination', 'label' => 'Tujuan'],
                ['key' => 'type', 'label' => 'Tipe'],
            ],
            'container-types' => [
                ['key' => 'label', 'label' => 'Label'],
                ['key' => 'length', 'label' => 'P (cm)'],
                ['key' => 'width', 'label' => 'L (cm)'],
                ['key' => 'height', 'label' => 'T (cm)'],
                ['key' => 'volume', 'label' => 'Vol (m³)'],
            ],
            'ports'           => [
                ['key' => 'city', 'label' => 'Kota'],
            ],
            default           => [],
        };

        return array_merge($cols, $extra);
    }

    private function getFields(string $type): array
    {
        return match ($type) {
            'branches' => [
                ['name' => 'code', 'label' => 'Kode Cabang', 'type' => 'text', 'required' => true, 'max' => 10],
                ['name' => 'name', 'label' => 'Nama Cabang', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'departments' => [
                ['name' => 'name', 'label' => 'Nama Departemen', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'routes' => [
                ['name' => 'name', 'label' => 'Nama Rute', 'type' => 'text', 'required' => true, 'max' => 150, 'placeholder' => 'cth: CHINA - JAKARTA (FCL)'],
                ['name' => 'origin', 'label' => 'Asal', 'type' => 'text', 'max' => 100],
                ['name' => 'destination', 'label' => 'Tujuan', 'type' => 'text', 'max' => 100],
                ['name' => 'type', 'label' => 'Tipe', 'type' => 'select', 'options' => ['FCL', 'LCL']],
            ],
            'countries' => [
                ['name' => 'code', 'label' => 'Kode Negara (ISO)', 'type' => 'text', 'required' => true, 'max' => 3, 'placeholder' => 'cth: ID, CN, SG'],
                ['name' => 'name', 'label' => 'Nama Negara', 'type' => 'text', 'required' => true, 'max' => 100],
                ['name' => 'phone_code', 'label' => 'Kode Telepon', 'type' => 'text', 'max' => 10, 'placeholder' => 'cth: +62'],
            ],
            'provinces' => [
                ['name' => 'country_id', 'label' => 'Negara', 'type' => 'parent-select', 'required' => true, 'source' => 'countries', 'labelFn' => "return it.name + ' (' + it.code + ')';"],
                ['name' => 'code', 'label' => 'Kode Provinsi', 'type' => 'text', 'max' => 10, 'placeholder' => 'cth: 31, GD'],
                ['name' => 'name', 'label' => 'Nama Provinsi', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'cities' => [
                ['name' => 'province_id', 'label' => 'Provinsi', 'type' => 'parent-select', 'required' => true, 'source' => 'provinces', 'labelFn' => "return it.name + (it.country ? ' — ' + it.country.name : '');"],
                ['name' => 'name', 'label' => 'Nama Kota', 'type' => 'text', 'required' => true, 'max' => 100],
                ['name' => 'type', 'label' => 'Tipe', 'type' => 'select', 'options' => ['kota', 'kabupaten']],
            ],
            'districts' => [
                ['name' => 'city_id', 'label' => 'Kota', 'type' => 'parent-select', 'required' => true, 'source' => 'cities', 'labelFn' => "return it.name + (it.province ? ' — ' + it.province.name : '');"],
                ['name' => 'name', 'label' => 'Nama Kecamatan', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'container-types' => [
                ['name' => 'code', 'label' => 'Kode', 'type' => 'text', 'required' => true, 'max' => 20, 'placeholder' => 'cth: 40ft HC'],
                ['name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true, 'max' => 50, 'placeholder' => 'cth: 40 Feet High Cube'],
                ['name' => 'length', 'label' => 'Panjang (cm)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
                ['name' => 'width', 'label' => 'Lebar (cm)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
                ['name' => 'height', 'label' => 'Tinggi (cm)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
                ['name' => 'volume', 'label' => 'Volume (m³)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
            ],
            'packaging-types' => [
                ['name' => 'name', 'label' => 'Nama Packaging', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'cbm-types' => [
                ['name' => 'name', 'label' => 'Nama Tipe CBM', 'type' => 'text', 'required' => true, 'max' => 50],
            ],
            'classifications' => [
                ['name' => 'name', 'label' => 'Nama Klasifikasi', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'count-units' => [
                ['name' => 'name', 'label' => 'Nama Satuan', 'type' => 'text', 'required' => true, 'max' => 50],
            ],
            'vessels' => [
                ['name' => 'code', 'label' => 'Kode Kapal', 'type' => 'text', 'required' => true, 'max' => 20],
                ['name' => 'name', 'label' => 'Nama Kapal', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'ports' => [
                ['name' => 'code', 'label' => 'Kode Pelabuhan', 'type' => 'text', 'required' => true, 'max' => 20],
                ['name' => 'name', 'label' => 'Nama Pelabuhan', 'type' => 'text', 'required' => true, 'max' => 100],
                ['name' => 'city', 'label' => 'Kota', 'type' => 'text', 'max' => 100],
            ],
            'pricing-items' => [
                ['name' => 'name', 'label' => 'Nama Item', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            default => [],
        };
    }

    public function data(string $type): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        $meta = self::TYPE_META[$type] ?? null;

        if (!$modelClass || !$meta) {
            return response()->json([]);
        }

        $query = $modelClass::orderBy($meta['order']);

        if ($type === 'provinces') {
            $query->with('country');
        } elseif ($type === 'cities') {
            $query->with('province.country');
        } elseif ($type === 'districts') {
            $query->with('city.province');
        }

        $items = $query->get()->map(function ($item) use ($type) {
            $row = $item->toArray();

            if ($type === 'provinces') {
                $row['country_name'] = $item->country?->name ?? '-';
            } elseif ($type === 'cities') {
                $row['province_name'] = $item->province?->name ?? '-';
                $row['country_name'] = $item->province?->country?->name ?? '-';
            } elseif ($type === 'districts') {
                $row['city_name'] = $item->city?->name ?? '-';
                $row['province_name'] = $item->city?->province?->name ?? '-';
            }

            return $row;
        });

        return response()->json($items);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => 'Tipe master tidak valid.'], 404);
        }

        $rules = $this->getValidationRules($type);
        $data = $request->validate($rules);

        if ($type === 'vessels') {
            $data['status'] = 'active';
        }

        $record = $modelClass::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil ditambahkan.',
            'data'    => $record,
        ]);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => 'Tipe master tidak valid.'], 404);
        }

        $record = $modelClass::findOrFail($id);
        $rules = $this->getValidationRules($type, $id);
        $data = $request->validate($rules);

        $record->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diperbarui.',
            'data'    => $record->fresh(),
        ]);
    }

    public function toggle(string $type, int $id): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => 'Tipe master tidak valid.'], 404);
        }

        $record = $modelClass::findOrFail($id);

        if ($type === 'vessels') {
            $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);
        } else {
            $record->update(['is_active' => !$record->is_active]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status berhasil diubah.',
            'data'    => $record->fresh(),
        ]);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => 'Tipe master tidak valid.'], 404);
        }

        $record = $modelClass::findOrFail($id);
        $record->delete();

        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus.']);
    }

    public function options(Request $request, string $type): JsonResponse
    {
        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json([]);
        }

        $query = $modelClass::query();

        if ($type === 'vessels') {
            $query->where('status', 'active');
        } elseif (method_exists($modelClass, 'scopeActive')) {
            $query->active();
        }

        if ($type === 'provinces' && $request->has('country_id')) {
            $query->where('country_id', $request->country_id);
        } elseif ($type === 'cities' && $request->has('province_id')) {
            $query->where('province_id', $request->province_id);
        } elseif ($type === 'districts' && $request->has('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        $order = in_array($type, ['branches', 'container-types']) ? 'code' : 'name';
        $data = $query->orderBy($order)->get();

        return response()->json($data);
    }

    private function getValidationRules(string $type, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';

        return match ($type) {
            'branches' => [
                'code'      => "required|string|max:10|unique:master_branches,code{$unique}",
                'name'      => 'required|string|max:100',
            ],
            'departments' => [
                'name'      => "required|string|max:100|unique:master_departments,name{$unique}",
            ],
            'routes' => [
                'name'        => "required|string|max:150|unique:master_routes,name{$unique}",
                'origin'      => 'nullable|string|max:100',
                'destination' => 'nullable|string|max:100',
                'type'        => 'nullable|string|in:FCL,LCL',
            ],
            'countries' => [
                'code'       => "required|string|max:3|unique:countries,code{$unique}",
                'name'       => 'required|string|max:100',
                'phone_code' => 'nullable|string|max:10',
            ],
            'provinces' => [
                'country_id' => 'required|exists:countries,id',
                'code'       => 'nullable|string|max:10',
                'name'       => 'required|string|max:100',
            ],
            'cities' => [
                'province_id' => 'required|exists:provinces,id',
                'name'        => 'required|string|max:100',
                'type'        => 'nullable|string|max:20',
            ],
            'districts' => [
                'city_id' => 'required|exists:cities,id',
                'name'    => 'required|string|max:100',
            ],
            'container-types' => [
                'code'   => "required|string|max:20|unique:master_container_types,code{$unique}",
                'label'  => 'required|string|max:50',
                'length' => 'required|numeric|min:0',
                'width'  => 'required|numeric|min:0',
                'height' => 'required|numeric|min:0',
                'volume' => 'required|numeric|min:0',
            ],
            'packaging-types' => [
                'name' => "required|string|max:100|unique:master_packaging_types,name{$unique}",
            ],
            'cbm-types' => [
                'name' => "required|string|max:50|unique:master_cbm_types,name{$unique}",
            ],
            'classifications' => [
                'name' => "required|string|max:100|unique:master_classifications,name{$unique}",
            ],
            'count-units' => [
                'name' => "required|string|max:50|unique:master_count_units,name{$unique}",
            ],
            'vessels' => [
                'name' => 'required|string|max:100',
                'code' => "required|string|max:20|unique:vessels,code{$unique}",
            ],
            'ports' => [
                'name' => 'required|string|max:100',
                'code' => "required|string|max:20|unique:ports,code{$unique}",
                'city' => 'required|string|max:100',
            ],
            'pricing-items' => [
                'name' => "required|string|max:100|unique:master_pricing_items,name{$unique}",
            ],
            default => [],
        };
    }
}
