<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterBranch;
use App\Models\MasterPricingItem;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class MarkingController extends Controller
{
    public function index(): View
    {
        $customers = User::role('customer')->orderBy('name')->get(['id', 'name', 'username']);
        $branches = MasterBranch::active()->orderBy('name')->get(['id', 'code', 'name']);
        $provinces = Province::active()->with('country:id,name')->orderBy('name')->get(['id', 'name', 'country_id']);
        $pricingItems = MasterPricingItem::active()->orderBy('name')->get(['id', 'name']);
        $salesUsers = User::role('sales')->orderBy('name')->get(['id', 'name']);

        return view('marking.index', compact('customers', 'branches', 'provinces', 'pricingItems', 'salesUsers'));
    }

    public function data(): JsonResponse
    {
        $markings = Marking::with([
                'customer:id,name,username,phone',
                'sales:id,name',
                'branch:id,code,name',
                'province:id,name',
                'city:id,name',
                'pricings.pricingItem:id,name',
            ])
            ->withCount('barangs')
            ->orderBy('code')
            ->get()
            ->map(function ($m) {
                $arr = $m->toArray();
                $arr['raw_code'] = $m->code;
                $arr['customer_phone'] = $m->customer?->phone ?? '-';
                $arr['branch_name'] = $m->branch?->name ?? '-';
                $arr['province_name'] = $m->province?->name ?? '-';
                $arr['city_name'] = $m->city?->name ?? '-';
                return $arr;
            });

        return response()->json($markings);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->markingRules($request));

        $marking = DB::transaction(function () use ($data) {
            $pricings = $data['pricings'] ?? [];
            unset($data['pricings']);

            $marking = Marking::create($data);

            foreach ($pricings as $p) {
                $marking->pricings()->create($p);
            }

            return $marking;
        });

        return response()->json([
            'success' => true,
            'message' => "Marking {$marking->code} berhasil ditambahkan.",
            'data'    => $marking->load('customer', 'branch', 'pricings.pricingItem'),
        ]);
    }

    public function update(Request $request, Marking $marking): JsonResponse
    {
        $data = $request->validate($this->markingRules($request, $marking->id));

        DB::transaction(function () use ($marking, $data) {
            $pricings = $data['pricings'] ?? [];
            unset($data['pricings']);

            $oldCode = $marking->code;
            $marking->update($data);

            if (isset($data['code']) && $data['code'] !== $oldCode) {
                Barang::where('marking', $oldCode)->update(['marking' => $data['code']]);
            }

            $marking->pricings()->delete();
            foreach ($pricings as $p) {
                $marking->pricings()->create($p);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Marking berhasil diperbarui.',
            'data'    => $marking->fresh()->load('customer', 'branch', 'pricings.pricingItem'),
        ]);
    }

    public function destroy(Marking $marking): JsonResponse
    {
        $marking->delete();

        return response()->json(['success' => true, 'message' => 'Marking berhasil dihapus.']);
    }

    public function toggle(Marking $marking): JsonResponse
    {
        $marking->update(['is_active' => !$marking->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Status marking berhasil diubah.',
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $query = Marking::forDropdown();

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($term = trim((string) $request->input('q', $request->input('term', '')))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%");
            });
        }

        $results = $query->get(['id', 'code', 'customer_name', 'user_id'])->map(fn (Marking $marking) => [
            'id' => $marking->id,
            'text' => $marking->dropdownLabel(),
            'code' => $marking->code,
            'customer_name' => $marking->customer_name,
            'user_id' => $marking->user_id,
        ]);

        return response()->json(['results' => $results]);
    }

    public function checkNik(Request $request): JsonResponse
    {
        $nik = $request->get('nik', '');
        $excludeId = $request->get('exclude_id');

        if (empty($nik)) {
            return response()->json(['exists' => false]);
        }

        $query = Marking::where('nik', $nik);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $match = $query->first(['id', 'code', 'nik']);

        return response()->json([
            'exists' => (bool) $match,
            'match'  => $match,
        ]);
    }

    public function getCities(Province $province): JsonResponse
    {
        $cities = $province->cities()->active()->orderBy('name')->get(['id', 'name', 'type']);

        return response()->json($cities);
    }

    /** @return array<string, mixed> */
    private function markingRules(Request $request, ?int $markingId = null): array
    {
        $provinceId = $request->input('province_id');

        return [
            'user_id'        => 'required|exists:users,id',
            'sales_id'       => 'nullable|exists:users,id',
            'code'           => $markingId
                ? "required|string|max:50|unique:markings,code,{$markingId}"
                : 'required|string|max:50|unique:markings,code',
            'description'    => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:255',
            'pic'            => 'nullable|string|max:100',
            'priority'       => 'nullable|string|in:vip,non_vip',
            'branch_id'      => 'nullable|exists:master_branches,id',
            'address_1'      => 'nullable|string|max:1000',
            'address_2'      => 'nullable|string|max:1000',
            'promo'          => 'nullable|string|max:255',
            'province_id'    => ['nullable', 'exists:provinces,id', Rule::requiredIf($request->filled('city_id'))],
            'city_id'        => [
                'nullable',
                'exists:cities,id',
                Rule::when(
                    $request->filled('city_id') && $request->filled('province_id'),
                    Rule::exists('cities', 'id')->where('province_id', $provinceId),
                ),
            ],
            'phone_hp'       => 'nullable|string|max:30',
            'fax'            => 'nullable|string|max:30',
            'phone_02'       => 'nullable|string|max:30',
            'wa_name'        => 'nullable|string|max:100',
            'nik'            => 'nullable|string|max:30',
            'nik_name'       => 'nullable|string|max:100',
            'passcode'       => 'nullable|string|max:20',
            'pricings'                    => 'nullable|array',
            'pricings.*.pricing_item_id'  => 'required_with:pricings|exists:master_pricing_items,id',
            'pricings.*.volume_price'     => 'nullable|numeric|min:0',
            'pricings.*.gw_price'         => 'nullable|numeric|min:0',
            'pricings.*.minimum_charge'   => 'nullable|numeric|min:0',
            'pricings.*.effective_start'  => 'nullable|date',
            'pricings.*.invoice_due_days' => 'nullable|integer|min:0',
            'pricings.*.special_volume'   => 'nullable|numeric|min:0',
        ];
    }
}
