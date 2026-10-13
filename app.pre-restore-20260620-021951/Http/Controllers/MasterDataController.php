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
use App\Models\ActivityLog;
use App\Models\MasterPacking;
use App\Models\MasterPackagingType;
use App\Models\MasterPromo;
use App\Models\SalesIncentiveTier;
use App\Models\MasterGudangChina;
use App\Models\MasterPricingItem;
use App\Models\MasterRoute;
use App\Models\Port;
use App\Models\Province;
use App\Models\Vessel;
use App\Support\CompanyRevenueSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        'gudang-china'    => MasterGudangChina::class,
        'packings'        => MasterPacking::class,
        'promos'          => MasterPromo::class,
        'sales-incentive-tiers' => SalesIncentiveTier::class,
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
        'gudang-china'    => ['title' => 'Gudang China',          'group' => 'Operasional',    'icon' => 'bi-house-door',      'order' => 'name'],
        'packings'        => ['title' => 'Tipe Packing',          'group' => 'Data Barang',    'icon' => 'bi-box2',            'order' => 'name'],
        'promos'          => ['title' => 'Promo',                 'group' => 'Operasional',    'icon' => 'bi-percent',         'order' => 'name'],
        'sales-incentive-tiers' => ['title' => 'Insentif Sales (CBM)', 'group' => 'Operasional', 'icon' => 'bi-gift', 'order' => 'sort_order'],
    ];

    public function page(string $type): View
    {
        $this->authorizeMasterAccess($type, 'view');

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
        } elseif ($type === 'sales-incentive-tiers') {
            $parentData['modal-pricing-items'] = collect(CompanyRevenueSettings::modalPricingItemOptions())
                ->map(static fn (array $row) => (object) $row);
        }

        $groupKey = match ($meta['group']) {
            'Operasional'    => 'master_data.group_operational',
            'Lokasi'         => 'master_data.group_location',
            'Data Barang'    => 'master_data.group_cargo',
            'Mitra & Armada' => 'master_data.group_fleet',
            default          => null,
        };

        return view('master-data.page', [
            'type'       => $type,
            'title'      => __('master_data.types.' . $type . '.title'),
            'group'      => $groupKey ? __($groupKey) : $meta['group'],
            'icon'       => $meta['icon'],
            'columns'    => $columns,
            'fields'     => $fields,
            'parentData' => $parentData,
            'hasToggle'  => in_array($type, ['promos', 'sales-incentive-tiers'], true),
            'isIncentiveMaster' => $type === 'sales-incentive-tiers',
            'modalPricingItemCount' => $type === 'sales-incentive-tiers'
                ? count(CompanyRevenueSettings::modalPricingItemOptions())
                : 0,
        ]);
    }

    private function getColumns(string $type): array
    {
        $cols = [];

        if ($type === 'gudang-china') {
            return [
                ['key' => 'name', 'label' => __('master_data.col_name')],
                ['key' => 'address', 'label' => __('master_data.col_address')],
            ];
        }

        if ($type === 'container-types') {
            return [
                ['key' => 'code', 'label' => __('master_data.col_code')],
                ['key' => 'label', 'label' => __('master_data.col_label')],
                ['key' => 'length', 'label' => __('master_data.col_length')],
                ['key' => 'width', 'label' => __('master_data.col_width')],
                ['key' => 'height', 'label' => __('master_data.col_height')],
                ['key' => 'volume', 'label' => __('master_data.col_volume')],
            ];
        }

        if (in_array($type, ['branches', 'vessels', 'ports', 'countries'])) {
            $cols[] = ['key' => 'code', 'label' => __('master_data.col_code')];
        }

        $cols[] = ['key' => 'name', 'label' => __('master_data.col_name')];

        if ($type === 'promos') {
            return [
                ['key' => 'name', 'label' => __('master_data.col_name')],
                ['key' => 'discount_label', 'label' => __('master_data.col_discount')],
                ['key' => 'status_label', 'label' => __('master_data.col_status')],
            ];
        }

        if ($type === 'sales-incentive-tiers') {
            return [
                ['key' => 'pricing_item_name', 'label' => __('master_data.col_pricing_category')],
                ['key' => 'name', 'label' => __('master_data.col_name')],
                ['key' => 'cbm_range', 'label' => __('master_data.col_cbm_range')],
                ['key' => 'cbm_per_block', 'label' => __('master_data.col_cbm_per_block')],
                ['key' => 'amount_per_block_label', 'label' => __('master_data.col_amount_per_block')],
                ['key' => 'sort_order', 'label' => __('master_data.col_sort_order')],
                ['key' => 'status_label', 'label' => __('master_data.col_status')],
            ];
        }

        if ($type === 'pricing-items') {
            return [
                ['key' => 'name', 'label' => __('master_data.col_name')],
                ['key' => 'volume_price', 'label' => __('master_data.col_volume_price')],
                ['key' => 'gw_price', 'label' => __('master_data.col_gw_price')],
            ];
        }

        $extra = match ($type) {
            'countries'       => [['key' => 'phone_code', 'label' => __('master_data.col_phone_code')]],
            'provinces'       => [
                ['key' => 'country_name', 'label' => __('master_data.col_country')],
                ['key' => 'code', 'label' => __('master_data.col_code')],
            ],
            'cities'          => [
                ['key' => 'province_name', 'label' => __('master_data.col_province')],
                ['key' => 'country_name', 'label' => __('master_data.col_country')],
                ['key' => 'type', 'label' => __('master_data.col_type')],
            ],
            'districts'       => [
                ['key' => 'city_name', 'label' => __('master_data.col_city')],
                ['key' => 'province_name', 'label' => __('master_data.col_province')],
            ],
            'routes'          => [
                ['key' => 'origin', 'label' => __('master_data.col_origin')],
                ['key' => 'destination', 'label' => __('master_data.col_destination')],
                ['key' => 'type', 'label' => __('master_data.col_type')],
            ],
            'container-types' => [
                ['key' => 'label', 'label' => __('master_data.col_label')],
                ['key' => 'length', 'label' => __('master_data.col_length')],
                ['key' => 'width', 'label' => __('master_data.col_width')],
                ['key' => 'height', 'label' => __('master_data.col_height')],
                ['key' => 'volume', 'label' => __('master_data.col_volume')],
            ],
            'ports'           => [
                ['key' => 'city', 'label' => __('master_data.col_city')],
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
                ['name' => 'volume_price', 'label' => __('master_data.field_volume_price'), 'type' => 'number', 'required' => true, 'step' => '0.01', 'min' => 0],
                ['name' => 'gw_price', 'label' => __('master_data.field_gw_price'), 'type' => 'number', 'required' => true, 'step' => '0.01', 'min' => 0],
            ],
            'gudang-china' => [
                ['name' => 'name', 'label' => 'Nama Gudang', 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'address', 'label' => 'Alamat', 'type' => 'textarea', 'max' => 500],
            ],
            'packings' => [
                ['name' => 'name', 'label' => 'Nama Packing', 'type' => 'text', 'required' => true, 'max' => 100],
            ],
            'promos' => [
                ['name' => 'name', 'label' => __('master_data.field_promo_name'), 'type' => 'text', 'required' => true, 'max' => 100],
                ['name' => 'type', 'label' => __('master_data.field_promo_type'), 'type' => 'select', 'required' => true, 'options' => [
                    MasterPromo::TYPE_PERCENT => __('master_data.promo_type_percent'),
                    MasterPromo::TYPE_FIXED_RP => __('master_data.promo_type_fixed_rp'),
                ]],
                ['name' => 'persen', 'label' => __('master_data.field_promo_persen'), 'type' => 'number', 'required' => false, 'step' => '0.01', 'min' => 0, 'max' => 100, 'promo_show_if_type' => MasterPromo::TYPE_PERCENT],
                ['name' => 'amount_rp', 'label' => __('master_data.field_promo_amount_rp'), 'type' => 'number', 'required' => false, 'step' => '1', 'min' => 0, 'promo_show_if_type' => MasterPromo::TYPE_FIXED_RP],
            ],
            'sales-incentive-tiers' => [
                ['name' => 'pricing_item_id', 'label' => __('master_data.field_pricing_category'), 'type' => 'parent-select', 'required' => true, 'source' => 'modal-pricing-items'],
                ['name' => 'name', 'label' => __('master_data.field_incentive_name'), 'type' => 'text', 'required' => true, 'max' => 120],
                ['name' => 'cbm_min', 'label' => __('master_data.field_cbm_min'), 'type' => 'number', 'required' => false, 'step' => '0.0001', 'min' => 0, 'placeholder' => __('master_data.placeholder_cbm_min')],
                ['name' => 'cbm_max', 'label' => __('master_data.field_cbm_max'), 'type' => 'number', 'required' => false, 'step' => '0.0001', 'min' => 0, 'placeholder' => __('master_data.placeholder_cbm_max')],
                ['name' => 'cbm_per_block', 'label' => __('master_data.field_cbm_per_block'), 'type' => 'number', 'required' => true, 'step' => '0.0001', 'min' => 0.0001],
                ['name' => 'amount_per_block', 'label' => __('master_data.field_amount_per_block'), 'type' => 'number', 'required' => true, 'step' => '1', 'min' => 0],
                ['name' => 'sort_order', 'label' => __('master_data.field_sort_order'), 'type' => 'number', 'required' => false, 'min' => 0],
            ],
            default => [],
        };
    }

    public function data(string $type): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'view');

        $modelClass = self::MASTER_MAP[$type] ?? null;
        $meta = self::TYPE_META[$type] ?? null;

        if (!$modelClass || !$meta) {
            return response()->json([]);
        }

        $query = $modelClass::orderBy($meta['order']);

        if ($type === 'sales-incentive-tiers') {
            $query->with('pricingItem:id,name');
        } elseif ($type === 'provinces') {
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
            } elseif ($type === 'promos') {
                $row['discount_label'] = $item->formattedDiscountValue();
                $row['raw_type'] = $item->type ?? MasterPromo::TYPE_PERCENT;
                $row['raw_persen'] = $item->persen;
                $row['raw_amount_rp'] = $item->amount_rp;
                $row['status_label'] = $item->is_active
                    ? __('master_data.status_active')
                    : __('master_data.status_inactive');
            } elseif ($type === 'sales-incentive-tiers') {
                $row['pricing_item_name'] = $item->pricingItem?->name ?? '-';
                $row['cbm_range'] = self::formatIncentiveCbmRange($item);
                $row['amount_per_block_label'] = 'Rp '.number_format((float) $item->amount_per_block, 0, ',', '.');
                $row['status_label'] = $item->is_active
                    ? __('master_data.status_active')
                    : __('master_data.status_inactive');
                $row['raw_pricing_item_id'] = $item->pricing_item_id;
                $row['raw_cbm_min'] = $item->cbm_min;
                $row['raw_cbm_max'] = $item->cbm_max;
                $row['raw_amount_per_block'] = $item->amount_per_block;
            }

            return $row;
        });

        return response()->json($items);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'create');

        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => __('messages.master_invalid_type')], 404);
        }

        $rules = $this->getValidationRules($type);
        $data = $request->validate($rules);

        if ($type === 'vessels') {
            $data['status'] = 'active';
        }

        if ($type === 'sales-incentive-tiers') {
            $data = self::normalizeIncentiveTierInput($data);
            $data['is_active'] = true;
        }

        if ($type === 'promos') {
            $data = self::normalizePromoInput($data);
            $data['is_active'] = true;
        }

        $record = $modelClass::create($data);

        if ($type === 'promos') {
            ActivityLog::record('Promo master created', $record);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.master_created'),
            'data'    => $record,
        ]);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'edit');

        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => __('messages.master_invalid_type')], 404);
        }

        $record = $modelClass::findOrFail($id);
        $rules = $this->getValidationRules($type, $id);
        $data = $request->validate($rules);

        if ($type === 'sales-incentive-tiers') {
            $data = self::normalizeIncentiveTierInput($data);
        }

        if ($type === 'promos') {
            $data = self::normalizePromoInput($data);
        }

        $old = $type === 'promos' ? $record->only(['name', 'type', 'persen', 'amount_rp', 'is_active']) : null;
        $record->update($data);

        if ($type === 'promos') {
            ActivityLog::record('Promo master updated', $record, $old, $record->fresh()->only(['name', 'type', 'persen', 'amount_rp', 'is_active']));
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.master_updated'),
            'data'    => $record->fresh(),
        ]);
    }

    public function toggle(string $type, int $id): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'edit');

        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => __('messages.master_invalid_type')], 404);
        }

        $record = $modelClass::findOrFail($id);

        if ($type === 'vessels') {
            $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);
        } else {
            $record->update(['is_active' => !$record->is_active]);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.master_status_changed'),
            'data'    => $record->fresh(),
        ]);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'delete');

        $modelClass = self::MASTER_MAP[$type] ?? null;
        if (!$modelClass) {
            return response()->json(['success' => false, 'message' => __('messages.master_invalid_type')], 404);
        }

        $record = $modelClass::findOrFail($id);

        if ($type === 'promos') {
            ActivityLog::record('Promo master deleted', $record);
        }

        $record->delete();

        return response()->json(['success' => true, 'message' => __('messages.master_deleted')]);
    }

    public function options(Request $request, string $type): JsonResponse
    {
        $this->authorizeMasterAccess($type, 'view');

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
                'volume_price' => 'required|numeric|min:0',
                'gw_price' => 'required|numeric|min:0',
            ],
            'gudang-china' => [
                'name'    => "required|string|max:150|unique:master_gudang_china,name{$unique}",
                'address' => 'nullable|string|max:500',
            ],
            'packings' => [
                'name' => "required|string|max:100|unique:master_packings,name{$unique}",
            ],
            'promos' => [
                'name'      => "required|string|max:100|unique:master_promos,name{$unique}",
                'type'      => 'required|in:'.MasterPromo::TYPE_PERCENT.','.MasterPromo::TYPE_FIXED_RP,
                'persen'    => 'required_if:type,'.MasterPromo::TYPE_PERCENT.'|nullable|numeric|min:0|max:100',
                'amount_rp' => 'required_if:type,'.MasterPromo::TYPE_FIXED_RP.'|nullable|numeric|min:0',
            ],
            'sales-incentive-tiers' => [
                'pricing_item_id' => [
                    'required',
                    'integer',
                    Rule::exists('company_revenue_item_modals', 'pricing_item_id'),
                ],
                'name' => "required|string|max:120|unique:sales_incentive_tiers,name{$unique}",
                'cbm_min' => 'nullable|numeric|min:0',
                'cbm_max' => 'nullable|numeric|min:0',
                'cbm_per_block' => 'required|numeric|min:0.0001',
                'amount_per_block' => 'required|numeric|min:0',
                'sort_order' => 'nullable|integer|min:0',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizePromoInput(array $data): array
    {
        $type = $data['type'] ?? MasterPromo::TYPE_PERCENT;
        $data['type'] = $type;

        if ($type === MasterPromo::TYPE_FIXED_RP) {
            $data['persen'] = null;
            $data['amount_rp'] = isset($data['amount_rp']) && $data['amount_rp'] !== ''
                ? (float) $data['amount_rp']
                : null;
        } else {
            $data['amount_rp'] = null;
            $data['persen'] = isset($data['persen']) && $data['persen'] !== ''
                ? (float) $data['persen']
                : null;
        }

        return $data;
    }

    private static function formatIncentiveCbmRange(SalesIncentiveTier $tier): string
    {
        $min = $tier->cbm_min !== null ? (float) $tier->cbm_min : null;
        $max = $tier->cbm_max !== null ? (float) $tier->cbm_max : null;

        if ($min === null && $max === null) {
            return __('master_data.cbm_range_all');
        }

        if ($min !== null && $max !== null) {
            return rtrim(rtrim(number_format($min, 2, ',', '.'), '0'), ',')
                .' – '
                .rtrim(rtrim(number_format($max, 2, ',', '.'), '0'), ',')
                .' CBM';
        }

        if ($min !== null) {
            return '≥ '.rtrim(rtrim(number_format($min, 2, ',', '.'), '0'), ',').' CBM';
        }

        return '≤ '.rtrim(rtrim(number_format((float) $max, 2, ',', '.'), '0'), ',').' CBM';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeIncentiveTierInput(array $data): array
    {
        $pricingItemId = (int) ($data['pricing_item_id'] ?? 0);
        if ($pricingItemId <= 0 || ! CompanyRevenueSettings::isModalPricingItemId($pricingItemId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pricing_item_id' => [__('master_data.pricing_category_invalid')],
            ]);
        }

        $data['pricing_item_id'] = $pricingItemId;

        foreach (['cbm_min', 'cbm_max'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                $data[$key] = null;

                continue;
            }

            $data[$key] = (float) $data[$key];
        }

        if ($data['cbm_min'] !== null && $data['cbm_max'] !== null && $data['cbm_max'] < $data['cbm_min']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cbm_max' => [__('master_data.cbm_max_gte_min')],
            ]);
        }

        return $data;
    }

    private function authorizeMasterAccess(string $type, string $action): void
    {
        if ($type === 'sales-incentive-tiers') {
            abort_unless(
                auth()->user()?->can('manage sales incentive master')
                || auth()->user()?->can('manage master data'),
                403,
            );

            return;
        }

        if ($type !== 'promos') {
            abort_unless(auth()->user()?->can('manage master data'), 403);

            return;
        }

        $permission = match ($action) {
            'view'   => 'view promo master',
            'create' => 'create promo master',
            'edit'   => 'edit promo master',
            'delete' => 'delete promo master',
            default  => null,
        };

        if ($permission === null) {
            return;
        }

        $user = auth()->user();

        abort_unless(
            $user && ($user->can($permission) || $user->can('manage master data')),
            403,
        );
    }
}
