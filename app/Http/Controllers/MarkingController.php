<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\MarkingExport;
use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterBranch;
use App\Models\MasterPricingItem;
use App\Models\User;
use App\Support\UploadedImageCompressor;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MarkingController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $isSalesScoped = Marking::userHasSalesMarkingScope($user);
        $isSupervisorScoped = Marking::userHasSupervisorMarkingScope($user);
        $scopedMarkingCount = Marking::userHasTeamMarkingScope($user)
            ? Marking::visibleToUser($user)->count()
            : 0;

        $branches = MasterBranch::active()->orderBy('name')->get(['id', 'code', 'name']);
        $pricingItems = MasterPricingItem::active()->orderBy('name')->get(['id', 'name', 'volume_price', 'gw_price']);

        $salesUsersQuery = User::query()->marketingStaff()->orderBy('name');
        if ($isSalesScoped) {
            $salesUsersQuery->where('id', $user->id);
        } elseif ($isSupervisorScoped) {
            $salesUsersQuery->whereIn('id', Marking::teamScopeUserIds($user));
        }
        $salesUsers = $salesUsersQuery->get(['id', 'name']);

        return view('marking.index', compact(
            'branches',
            'pricingItems',
            'salesUsers',
            'isSalesScoped',
            'isSupervisorScoped',
            'scopedMarkingCount',
        ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('export marking'), 403);

        // Always include Effective Start + Pricing (Kategori / Harga / Item) in the file.
        // UI "show prices" toggle only affects the on-screen table, not export columns.
        $showPrices = true;
        $rows = $this->buildExportRows($request->merge(['show_prices' => '1']));
        $filterSummary = $this->exportFilterSummary($request, $showPrices);

        ActivityLog::record('Marking exported', null, null, [
            'count' => $rows->count(),
            'filters' => $filterSummary,
        ]);

        $extension = $request->string('format')->toString() === 'csv' ? 'csv' : 'xlsx';
        $writerType = $extension === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $filename = 'marking_' . now()->format('Y-m-d_His') . '.' . $extension;

        return Excel::download(
            new MarkingExport($rows, $showPrices),
            $filename,
            $writerType,
        );
    }

    public function data(Request $request): JsonResponse
    {
        [$period, $periodFrom, $periodTo] = $this->periodFilterFromRequest($request);

        $relatedMarkingWith = ['id', 'customer_name'];
        if ($relatedCodeCol = Marking::codeColumn()) {
            $relatedMarkingWith[] = $relatedCodeCol;
        }

        $query = Marking::visibleToUser()
            ->withLastShippingAt()
            ->filterByPeriod($period, $periodFrom, $periodTo)
            ->with([
                'customer:id,name,username,phone',
                'sales:id,name',
                'branch:id,code,name',
                'relatedMarking:' . implode(',', $relatedMarkingWith),
                'pricings.pricingItem:id,name,volume_price,gw_price',
            ]);

        $salesFilterId = $request->input('sales_id', $request->input('marketing_id'));
        if ($salesFilterId !== null && $salesFilterId !== '') {
            $this->applyMarketingFilter($query, (int) $salesFilterId);
        }

        if (config('app.debug')) {
            Log::debug('marking.data query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        }

        $markings = $query
            ->orderByCode()
            ->get()
            ->map(function ($m) {
                $arr = $m->toArray();
                $arr['raw_code'] = $m->displayCode();
                if (! array_key_exists('code', $arr) && Marking::codeColumn() === null) {
                    $arr['code'] = $m->displayCode();
                }
                $arr['customer_phone'] = $m->customer?->phone ?? '-';
                $arr['branch_name'] = $m->branch?->name ?? '-';
                $arr['sales_name'] = $m->sales?->name;
                $arr['last_shipping_at'] = self::formatLastShippingDate($m->last_shipping_at);

                $effectivePricing = $m->currentEffectivePricing();
                $arr['display_volume_price'] = $effectivePricing?->volume_price;
                $arr['display_gw_price'] = $effectivePricing?->gw_price;
                $arr['effective_start'] = $m->effective_start?->format('Y-m-d');
                $invoiceDueDays = (int) ($m->invoice_due_days ?? 0);
                if ($invoiceDueDays <= 0 && Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
                    $fromPricings = (int) ($m->pricings->max('invoice_due_days') ?? 0);
                    if ($fromPricings > 0) {
                        $invoiceDueDays = $fromPricings;
                    }
                }
                $arr['invoice_due_days'] = $invoiceDueDays;
                $arr['max_weight_kg'] = $m->max_weight_kg ?? 0;
                $arr['minimum_charge'] = $m->resolvedMinimumCharge();
                $arr['pricing_is_effective'] = $m->isPricingEffectiveAsOf();
                $arr['ktp_photo_path'] = $m->ktp_photo_path;
                $arr['ktp_photo_url'] = $m->ktpPhotoUrl();
                if (Marking::hasColumnCached('markings', 'related_marking_id')) {
                    $arr['related_marking_id'] = $m->related_marking_id !== null
                        ? (int) $m->related_marking_id
                        : null;
                    $arr['related_marking_label'] = $m->relatedMarking?->dropdownLabel() ?? '';
                } else {
                    $arr['related_marking_id'] = null;
                    $arr['related_marking_label'] = '';
                }
                $arr['pricings'] = $m->pricings->map(static function (MarkingPricing $p) {
                    $row = [
                        'id' => $p->id,
                        'pricing_item_id' => $p->pricing_item_id,
                        'pricing_item_name' => $p->pricingItem?->name,
                        'pricing_item' => $p->pricingItem ? [
                            'id' => $p->pricingItem->id,
                            'name' => $p->pricingItem->name,
                        ] : null,
                        'item_name' => Marking::hasColumnCached('marking_pricings', 'item_name')
                            ? ($p->item_name ?? null)
                            : null,
                        'volume_price' => $p->volume_price,
                        'gw_price' => $p->gw_price,
                    ];
                    if (Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
                        $row['invoice_due_days'] = (int) ($p->invoice_due_days ?? 0);
                    }

                    return $row;
                })->values()->all();

                return $arr;
            });

        return response()->json($markings);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('create marking'), 403);

        $request->merge(self::normalizePricingPayload($request->all()));
        $data = $request->validate($this->markingRules($request));
        $data = self::mergeRelatedMarkingFromRequest($request, $data);

        if (config('app.debug')) {
            Log::debug('marking.store related_marking_id', [
                'related_marking_id' => $data['related_marking_id'] ?? '(not in payload)',
            ]);
        }

        $user = auth()->user();
        if (Marking::userHasSalesMarkingScope($user)) {
            $data['sales_id'] = $user->id;
        }

        $canManagePricing = (bool) $user?->can('manage marking pricing');

        $marking = DB::transaction(function () use ($request, $data, $canManagePricing) {
            $pricings = $canManagePricing ? ($data['pricings'] ?? []) : [];
            unset($data['pricings'], $data['ktp_photo'], $data['remove_ktp_photo']);

            $marking = Marking::create(Marking::prepareAttributesForSave($data));

            foreach (self::dedupePricingRows($pricings) as $p) {
                $marking->pricings()->create(self::pricingRowAttributes($p));
            }

            self::syncKtpPhoto($request, $marking);

            // Customer portal wajib untuk invoice, meski passcode belum diisi.
            $marking->ensureCustomerUser();
            $marking->syncRelationClusterUser();
            $marking->syncBarangCustomerUserIds();

            return $marking;
        });

        $marking->refresh();
        if ((float) $marking->resolvedMinimumCharge() > 0) {
            ActivityLog::record(
                'Marking minimum CBM charge set',
                $marking,
                null,
                ['minimum_charge' => $marking->resolvedMinimumCharge()],
            );
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.marking_created', ['code' => $marking->displayCode()]),
            'data'    => self::markingPayloadForClient($marking->load('customer', 'branch', 'relatedMarking', 'pricings.pricingItem')),
        ]);
    }

    public function update(Request $request, Marking $marking): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit marking'), 403);
        abort_unless($marking->isVisibleToUser(), 403);

        $request->merge(self::normalizePricingPayload($request->all()));
        $data = $request->validate($this->markingRules($request, $marking->id));
        $data = self::mergeRelatedMarkingFromRequest($request, $data, $marking->id);

        if (config('app.debug')) {
            Log::debug('marking.update related_marking_id', [
                'marking_id' => $marking->id,
                'related_marking_id' => $data['related_marking_id'] ?? '(not in payload)',
            ]);
        }

        $oldMinCharge = $marking->resolvedMinimumCharge();
        $canManagePricing = (bool) auth()->user()?->can('manage marking pricing');

        DB::transaction(function () use ($request, $marking, $data, $canManagePricing) {
            $pricings = $canManagePricing ? ($data['pricings'] ?? []) : [];
            unset($data['pricings'], $data['ktp_photo'], $data['remove_ktp_photo']);

            $codeCol = Marking::codeColumn();
            $oldCode = $codeCol !== null ? $marking->getAttribute($codeCol) : null;
            $marking->update(Marking::prepareAttributesForSave($data));

            if (
                $codeCol !== null
                && isset($data['code'])
                && $data['code'] !== $oldCode
                && Marking::hasColumnCached('barangs', 'marking')
            ) {
                Barang::where('marking', $oldCode)->update(['marking' => $data['code']]);
            }

            if ($canManagePricing) {
                $marking->pricings()->delete();
                foreach (self::dedupePricingRows($pricings) as $p) {
                    $marking->pricings()->create(self::pricingRowAttributes($p));
                }
            }

            self::syncKtpPhoto($request, $marking);

            // Customer portal wajib untuk invoice, meski passcode belum diisi.
            $marking->ensureCustomerUser();
            $marking->syncRelationClusterUser();
            $marking->syncBarangCustomerUserIds();
        });

        $marking->refresh();
        $newMinCharge = $marking->resolvedMinimumCharge();
        if ($oldMinCharge !== $newMinCharge) {
            ActivityLog::record(
                'Marking minimum CBM charge updated',
                $marking,
                ['minimum_charge' => $oldMinCharge],
                ['minimum_charge' => $newMinCharge],
            );
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.marking_updated'),
            'data'    => self::markingPayloadForClient($marking->fresh()->load('customer', 'branch', 'relatedMarking', 'pricings.pricingItem')),
        ]);
    }

    public function destroy(Marking $marking): JsonResponse
    {
        abort_unless(auth()->user()?->can('delete marking'), 403);
        abort_unless($marking->isVisibleToUser(), 403);

        $marking->delete();

        return response()->json(['success' => true, 'message' => __('messages.marking_deleted')]);
    }

    public function toggle(Marking $marking): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit marking'), 403);
        abort_unless($marking->isVisibleToUser(), 403);

        $marking->update(['is_active' => !$marking->is_active]);

        return response()->json([
            'success' => true,
            'message' => __('messages.marking_status_changed'),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $query = Marking::forDropdown()->visibleToUser();

        if ($excludeId = $request->integer('exclude_id')) {
            $query->whereKeyNot($excludeId);
        }

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($term = trim((string) $request->input('q', $request->input('term', '')))) {
            $codeCol = Marking::codeColumn();
            $query->where(function ($builder) use ($term, $codeCol) {
                if ($codeCol !== null) {
                    $builder->where($codeCol, 'like', "%{$term}%");
                }
                if (Marking::hasColumnCached('markings', 'customer_name')) {
                    $builder->orWhere('customer_name', 'like', "%{$term}%");
                }
            });
        }

        $select = ['id', 'customer_name', 'user_id'];
        if ($codeCol = Marking::codeColumn()) {
            $select[] = $codeCol === 'code' ? 'code' : $codeCol;
        }

        $results = $query
            ->orderByDesc('id')
            ->limit(30)
            ->get($select)
            ->map(fn (Marking $marking) => [
            'id' => $marking->id,
            'text' => $marking->dropdownLabel(),
            'code' => $marking->displayCode(),
            'customer_name' => $marking->customer_name,
            'user_id' => $marking->user_id,
        ]);

        return response()->json(['results' => $results]);
    }

    public function customerUsers(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit marking'), 403);

        $query = User::role('customer')->orderBy('name');

        if ($id = $request->input('id')) {
            $query->whereKey($id);
        }

        if ($term = trim((string) $request->input('q', $request->input('term', '')))) {
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
                if (Marking::hasColumnCached('users', 'marking_code')) {
                    $builder->orWhere('marking_code', 'like', "%{$term}%");
                }
            });
        }

        $results = $query
            ->limit(30)
            ->get(['id', 'name', 'username', 'phone', 'marking_code'])
            ->map(static function (User $user): array {
                $suffix = trim((string) ($user->username ?? ''));
                if ($suffix !== '') {
                    $suffix = ' (@' . $suffix . ')';
                }

                return [
                    'id' => $user->id,
                    'text' => trim((string) $user->name) . $suffix,
                    'name' => $user->name,
                    'username' => $user->username,
                ];
            });

        return response()->json(['results' => $results]);
    }

    public function checkNik(Request $request): JsonResponse
    {
        $nik = $request->get('nik', '');
        $excludeId = $request->get('exclude_id');

        if (empty($nik)) {
            return response()->json(['exists' => false]);
        }

        $query = Marking::visibleToUser()->where('nik', $nik);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $select = ['id', 'nik'];
        if ($codeCol = Marking::codeColumn()) {
            $select[] = $codeCol;
        }
        $match = $query->first($select);

        return response()->json([
            'exists' => (bool) $match,
            'match'  => $match ? [
                'id' => $match->id,
                'code' => $match->displayCode(),
                'raw_code' => $match->displayCode(),
            ] : null,
        ]);
    }

    public function template(Marking $marking): JsonResponse
    {
        abort_unless($marking->isVisibleToUser(), 403);

        $marking->load([
            'customer:id,name',
            'sales:id,name',
            'branch:id,code,name',
            'pricings.pricingItem:id,name,volume_price,gw_price',
        ]);

        return response()->json([
            'id' => $marking->id,
            'code' => $marking->displayCode(),
            'user_id' => $marking->user_id,
            'customer_name' => $marking->customer_name,
            'sales_id' => $marking->sales_id,
            'description' => $marking->description,
            'contact_person' => $marking->contact_person,
            'email' => $marking->email,
            'pic' => $marking->pic,
            'priority' => $marking->priority,
            'branch_id' => $marking->branch_id,
            'address_1' => $marking->address_1,
            'address_2' => $marking->address_2,
            'phone_hp' => $marking->phone_hp,
            'fax' => $marking->fax,
            'phone_02' => $marking->phone_02,
            'wa_name' => $marking->wa_name,
            'nik' => $marking->nik,
            'nik_name' => $marking->nik_name,
            'ktp_photo_path' => $marking->ktp_photo_path,
            'ktp_photo_url' => $marking->ktpPhotoUrl(),
            'passcode' => $marking->passcode,
            'effective_start' => $marking->effective_start?->format('Y-m-d'),
            'invoice_due_days' => $marking->invoice_due_days ?? 0,
            'max_weight_kg' => $marking->max_weight_kg ?? 0,
            'minimum_charge' => $marking->resolvedMinimumCharge(),
            'pricings' => $marking->pricings->map(static function (MarkingPricing $p) {
                $row = [
                    'pricing_item_id' => $p->pricing_item_id,
                    'item_name' => Marking::hasColumnCached('marking_pricings', 'item_name')
                        ? ($p->item_name ?? null)
                        : null,
                    'volume_price' => $p->volume_price,
                    'gw_price' => $p->gw_price,
                ];

                return $row;
            })->values(),
        ]);
    }

    public function pricings(Marking $marking): JsonResponse
    {
        $user = auth()->user();
        abort_unless(
            $user !== null && (
                $user->can('view marking')
                || $user->can('create barang')
                || $user->can('edit barang')
            ),
            403,
        );
        abort_unless($marking->isVisibleToUser($user), 403);

        $pricingIsEffective = $marking->isPricingEffectiveAsOf();
        $pricings = $marking->pricings()
            ->with('pricingItem:id,name,volume_price,gw_price')
            ->orderBy('id')
            ->get()
            ->map(function (MarkingPricing $pricing) {
                $row = [
                    'pricing_item_id' => $pricing->pricing_item_id,
                    'pricing_item_name' => $pricing->pricingItem?->name,
                    'item_name' => Marking::hasColumnCached('marking_pricings', 'item_name')
                        ? ($pricing->item_name ?? null)
                        : null,
                    'volume_price' => $pricing->volume_price,
                    'gw_price' => $pricing->gw_price,
                ];

                return $row;
            })
            ->values();

        return response()->json([
            'marking_id' => $marking->id,
            'code' => $marking->displayCode(),
            'pricing_is_effective' => $pricingIsEffective,
            'effective_start' => $marking->effective_start?->format('Y-m-d'),
            'invoice_due_days' => $marking->invoice_due_days ?? 0,
            'max_weight_kg' => $marking->max_weight_kg ?? 0,
            'minimum_charge' => $marking->resolvedMinimumCharge(),
            'pricings' => $pricings,
        ]);
    }

    /** @return Collection<int, array<int, mixed>> */
    private function buildExportRows(Request $request): Collection
    {
        [$period, $periodFrom, $periodTo] = $this->periodFilterFromRequest($request);

        $showPrices = $request->boolean('show_prices');

        $query = Marking::visibleToUser()
            ->withLastShippingAt()
            ->filterByPeriod($period, $periodFrom, $periodTo)
            ->with([
                'customer:id,name,username,phone',
                'sales:id,name',
                'branch:id,code,name',
                'pricings.pricingItem:id,name,volume_price,gw_price',
            ])
            ->withCount('barangs');

        $salesFilterId = $request->input('sales_id', $request->input('marketing_id'));
        if ($salesFilterId !== null && $salesFilterId !== '') {
            $this->applyMarketingFilter($query, (int) $salesFilterId);
        }

        $markings = $query->get();
        $search = mb_strtolower(trim($request->string('search')->toString()));

        if ($search !== '') {
            $markings = $markings->filter(fn (Marking $marking) => $this->markingMatchesSearch($marking, $search));
        }

        $sort = $request->string('sort')->toString() ?: 'code-asc';
        $markings = $this->sortMarkingsForExport($markings->values(), $sort);

        return $markings->flatMap(fn (Marking $marking) => $this->markingToExportRows($marking, $showPrices))->values();
    }

    /** @return array<string, mixed> */
    private function exportFilterSummary(Request $request, bool $showPrices): array
    {
        $salesFilterId = $request->input('sales_id', $request->input('marketing_id'));

        return array_filter([
            'period' => $this->periodFilterFromRequest($request)[0] ?: null,
            'sales_id' => $salesFilterId !== null && $salesFilterId !== '' ? (int) $salesFilterId : null,
            'search' => trim($request->string('search')->toString()) ?: null,
            'sort' => $request->string('sort')->toString() ?: 'code-asc',
            'show_prices' => $showPrices,
            'format' => $request->string('format')->toString() === 'csv' ? 'csv' : 'xlsx',
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function markingMatchesSearch(Marking $marking, string $searchTerm): bool
    {
        $searchable = mb_strtolower(implode(' ', array_filter([
            $marking->displayCode(),
            $marking->customer_name,
            $marking->customer?->username,
            $marking->customer?->phone,
            $marking->description,
            $marking->email,
            $marking->address_1,
            $marking->address_2,
            $marking->phone_hp,
            $marking->phone_02,
            $marking->fax,
            $marking->branch?->name,
            $marking->sales?->name,
            $marking->contact_person,
            $marking->nik,
        ], static fn ($value) => $value !== null && $value !== '')));

        foreach (preg_split('/\s+/u', $searchTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (! str_contains($searchable, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Marking>  $markings
     * @return Collection<int, Marking>
     */
    private function sortMarkingsForExport(Collection $markings, string $sort): Collection
    {
        $parts = explode('-', $sort, 2);
        $field = $parts[0] ?? 'code';
        $direction = ($parts[1] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $sorted = $markings->sort(function (Marking $a, Marking $b) use ($field, $direction): int {
            $va = match ($field) {
                'name' => mb_strtolower((string) ($a->customer_name ?? '')),
                'barangs' => (int) ($a->barangs_count ?? 0),
                default => mb_strtolower($a->displayCode()),
            };
            $vb = match ($field) {
                'name' => mb_strtolower((string) ($b->customer_name ?? '')),
                'barangs' => (int) ($b->barangs_count ?? 0),
                default => mb_strtolower($b->displayCode()),
            };

            $cmp = $va <=> $vb;

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return $sorted->values();
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function markingToExportRows(Marking $marking, bool $showPrices): array
    {
        $address = trim(implode(', ', array_filter([
            $marking->address_1,
            $marking->address_2,
        ])));

        $invoiceDueDays = (int) ($marking->invoice_due_days ?? 0);
        if ($invoiceDueDays <= 0 && Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
            $fromPricings = (int) ($marking->pricings->max('invoice_due_days') ?? 0);
            if ($fromPricings > 0) {
                $invoiceDueDays = $fromPricings;
            }
        }

        $minCharge = (float) $marking->resolvedMinimumCharge();

        $base = [
            $marking->branch?->name ?? '',
            $marking->sales?->name ?? '',
            $marking->displayCode(),
            $marking->customer_name ?? '',
            $address,
            $marking->phone_hp ?? '',
            $marking->phone_02 ?? '',
            $this->exportStatusLabel($marking),
            $invoiceDueDays > 0 ? $invoiceDueDays : '',
            $minCharge > 0 ? $minCharge : '',
        ];

        $tail = [
            $marking->nik ?? '',
            $marking->nik_name ?? '',
            '',
        ];

        if (! $showPrices) {
            return [array_merge($base, $tail)];
        }

        $maxWeight = $marking->max_weight_kg !== null && (float) $marking->max_weight_kg > 0
            ? (float) $marking->max_weight_kg
            : '';
        $effectiveStart = $marking->effective_start?->format('Y-m-d') ?? '';

        $pricingGroups = $this->exportPricingGroups($marking);
        if ($pricingGroups === []) {
            return [array_merge($base, [$maxWeight, $effectiveStart, '', '', ''], $tail)];
        }

        $rows = [];
        foreach ($pricingGroups as $group) {
            $rows[] = array_merge(
                $base,
                [$maxWeight, $effectiveStart, $group['kategori'], $group['harga'], $group['item']],
                $tail,
            );
        }

        return $rows;
    }

    /**
     * Group marking pricings by volume_price.
     * Kategori = master pricing item name, Harga = Rp formatted,
     * Item = nama item (marking_pricings.item_name); falls back to other kategori names when empty.
     *
     * @return list<array{kategori: string, harga: string, item: string}>
     */
    private function exportPricingGroups(Marking $marking): array
    {
        $pricings = $marking->pricings
            ->sortBy('id')
            ->values();

        if ($pricings->isEmpty()) {
            return [];
        }

        $groups = $pricings->groupBy(
            static fn (MarkingPricing $pricing): string => number_format((float) ($pricing->volume_price ?? 0), 2, '.', ''),
        );

        $result = [];
        foreach ($groups as $priceKey => $group) {
            $kategoriNames = $group
                ->map(static fn (MarkingPricing $pricing): string => trim((string) ($pricing->pricingItem?->name ?? '')))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values();

            if ($kategoriNames->isEmpty()) {
                continue;
            }

            $itemNames = $group
                ->flatMap(static function (MarkingPricing $pricing): array {
                    $raw = trim((string) ($pricing->item_name ?? ''));
                    if ($raw === '') {
                        return [];
                    }

                    $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];

                    return array_values(array_filter(
                        array_map(static fn (string $part): string => trim($part), $parts),
                        static fn (string $part): bool => $part !== '',
                    ));
                })
                ->values();

            if ($itemNames->isEmpty()) {
                $itemNames = $kategoriNames->count() > 1
                    ? $kategoriNames->slice(1)->values()
                    : $kategoriNames;
            }

            $result[] = [
                'kategori' => (string) $kategoriNames->first(),
                'harga' => $this->formatExportRupiah((float) $priceKey),
                'item' => $itemNames->implode("\n"),
            ];
        }

        return $result;
    }

    private function formatExportRupiah(float $amount): string
    {
        if ($amount <= 0) {
            return '';
        }

        return 'Rp '.number_format($amount, 0, '.', ',');
    }

    private function exportStatusLabel(Marking $marking): string
    {
        if ($marking->priority === 'vip') {
            return (string) __('marking.priority_vip');
        }

        $lastShip = $marking->last_shipping_at ?? null;
        if ($lastShip !== null && $lastShip !== '') {
            try {
                $formatted = Carbon::parse($lastShip)->locale(app()->getLocale())->translatedFormat('d M Y');
                if ($formatted !== '' && ! str_starts_with($formatted, '1970')) {
                    return $formatted;
                }
            } catch (\Throwable) {
                return (string) $lastShip;
            }
        }

        return (string) __('marking.never_shipped');
    }

    private static function formatLastShippingDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value)->toDateString();
            if ($date === '1970-01-01') {
                return null;
            }

            return $date;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function markingRules(Request $request, ?int $markingId = null): array
    {
        $rules = [
            'customer_name'  => 'required|string|max:150',
            'user_id'        => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    static fn (Builder $query) => $query->whereIn(
                        'id',
                        User::role('customer')->select('id'),
                    ),
                ),
            ],
            'sales_id'       => 'nullable|exists:users,id',
            'description'    => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:255',
            'pic'            => 'nullable|string|max:100',
            'priority'       => 'nullable|string|in:vip,non_vip',
            'branch_id'      => 'nullable|exists:master_branches,id',
            'address_1'      => 'nullable|string|max:1000',
            'address_2'      => 'nullable|string|max:1000',
            'phone_hp'       => 'nullable|string|max:30',
            'fax'            => 'nullable|string|max:30',
            'phone_02'       => 'nullable|string|max:30',
            'wa_name'        => 'nullable|string|max:100',
            'nik'            => 'nullable|string|max:30',
            'nik_name'       => 'nullable|string|max:100',
            'ktp_photo'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_ktp_photo' => 'nullable|boolean',
            'passcode'       => 'nullable|string|max:20',
            'effective_start' => 'nullable|date',
            'invoice_due_days' => 'nullable|integer|min:0',
            'max_weight_kg' => 'nullable|numeric|min:0',
            'minimum_charge' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'pricings'                    => 'nullable|array',
            'pricings.*.pricing_item_id'  => 'required_with:pricings|exists:master_pricing_items,id',
            'pricings.*.item_name'        => 'nullable|string|max:2000',
            'pricings.*.volume_price'     => 'nullable|numeric|min:0',
            'pricings.*.gw_price'         => 'nullable|numeric|min:0',
        ];

        if ($codeCol = Marking::codeColumn()) {
            $rules['code'] = $markingId
                ? "required|string|max:50|unique:markings,{$codeCol},{$markingId}"
                : "required|string|max:50|unique:markings,{$codeCol}";
        }

        if (Marking::hasColumnCached('markings', 'related_marking_id')) {
            $rules['related_marking_id'] = [
                'nullable',
                'integer',
                Rule::exists('markings', 'id'),
            ];
        }

        return $rules;
    }

    /** @param  array<string, mixed>  $data */
    private static function normalizePricingPayload(array $data): array
    {
        if (array_key_exists('user_id', $data) && ($data['user_id'] === '' || $data['user_id'] === null)) {
            $data['user_id'] = null;
        }

        if (array_key_exists('related_marking_id', $data) && ($data['related_marking_id'] === '' || $data['related_marking_id'] === null)) {
            $data['related_marking_id'] = null;
        } elseif (array_key_exists('related_marking_id', $data) && $data['related_marking_id'] !== null) {
            $data['related_marking_id'] = (int) $data['related_marking_id'];
        }

        if (array_key_exists('effective_start', $data) && ($data['effective_start'] === '' || $data['effective_start'] === null)) {
            $data['effective_start'] = null;
        }

        if (array_key_exists('invoice_due_days', $data) && ($data['invoice_due_days'] === '' || $data['invoice_due_days'] === null)) {
            $data['invoice_due_days'] = 0;
        }

        if (array_key_exists('max_weight_kg', $data) && ($data['max_weight_kg'] === '' || $data['max_weight_kg'] === null)) {
            $data['max_weight_kg'] = 0;
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private static function mergeRelatedMarkingFromRequest(Request $request, array $data, ?int $markingId = null): array
    {
        $data = self::normalizePricingPayload($data);

        if (! Marking::hasColumnCached('markings', 'related_marking_id')) {
            unset($data['related_marking_id']);

            return $data;
        }

        if ($request->exists('related_marking_id')) {
            $normalized = self::normalizePricingPayload([
                'related_marking_id' => $request->input('related_marking_id'),
            ]);
            $data['related_marking_id'] = $normalized['related_marking_id'] ?? null;
        }

        return self::normalizeRelatedMarkingPayload($data, $markingId);
    }

    /** @param  array<string, mixed>  $data */
    private static function normalizeRelatedMarkingPayload(array $data, ?int $markingId = null): array
    {
        if (! array_key_exists('related_marking_id', $data) || $data['related_marking_id'] === null) {
            return $data;
        }

        $relatedId = (int) $data['related_marking_id'];
        if ($relatedId <= 0 || ($markingId !== null && $relatedId === $markingId)) {
            $data['related_marking_id'] = null;

            return $data;
        }

        if (! Marking::query()->whereKey($relatedId)->exists()) {
            $data['related_marking_id'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function markingPayloadForClient(Marking $marking): array
    {
        $payload = $marking->toArray();
        $payload['raw_code'] = $marking->displayCode();

        if (Marking::hasColumnCached('markings', 'related_marking_id')) {
            $payload['related_marking_id'] = $marking->related_marking_id !== null
                ? (int) $marking->related_marking_id
                : null;
            $payload['related_marking_label'] = $marking->relatedMarking?->dropdownLabel() ?? '';
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function dedupePricingRows(array $rows): array
    {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $itemId = (int) ($row['pricing_item_id'] ?? 0);
            if ($itemId <= 0 || isset($seen[$itemId])) {
                continue;
            }
            $seen[$itemId] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    /** @param  array<string, mixed>  $row */
    private static function pricingRowAttributes(array $row): array
    {
        $attrs = [
            'pricing_item_id' => (int) $row['pricing_item_id'],
            'volume_price' => max(0, (float) ($row['volume_price'] ?? 0)),
            'gw_price' => max(0, (float) ($row['gw_price'] ?? 0)),
        ];

        if (Marking::hasColumnCached('marking_pricings', 'item_name')) {
            $name = trim((string) ($row['item_name'] ?? ''));
            $attrs['item_name'] = $name !== '' ? $name : null;
        }

        return $attrs;
    }

    private static function syncKtpPhoto(Request $request, Marking $marking, ?Marking $relatedSource = null): void
    {
        if (! Marking::hasColumnCached('markings', 'ktp_photo_path')) {
            return;
        }

        $oldPath = $marking->ktp_photo_path;

        if ($request->boolean('remove_ktp_photo')) {
            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
                $marking->update(['ktp_photo_path' => null]);
                ActivityLog::record(
                    'Marking KTP photo removed',
                    $marking,
                    ['ktp_photo_path' => $oldPath],
                    null,
                );
            }

            return;
        }

        $file = $request->file('ktp_photo');
        if ($file === null) {
            if (
                $relatedSource !== null
                && $relatedSource->ktp_photo_path
                && ! $marking->ktp_photo_path
            ) {
                $marking->update(['ktp_photo_path' => $relatedSource->ktp_photo_path]);
            }

            return;
        }

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $newPath = UploadedImageCompressor::store($file, 'markings-ktp', 'public');
        $marking->update(['ktp_photo_path' => $newPath]);

        if ($oldPath) {
            ActivityLog::record(
                'Marking KTP photo updated',
                $marking,
                ['ktp_photo_path' => $oldPath],
                ['ktp_photo_path' => $newPath],
            );
        } else {
            ActivityLog::record(
                'Marking KTP photo uploaded',
                $marking,
                null,
                ['ktp_photo_path' => $newPath],
            );
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function periodFilterFromRequest(Request $request): array
    {
        $period = \App\Support\DatePresetFilter::normalizePreset(
            $request->string('period')->toString() ?: null,
        );
        if ($period === '' && ($request->filled('tanggal_from') || $request->filled('tanggal_to'))) {
            $period = 'custom';
        }
        if (! in_array($period, Marking::periodFilterValues(), true)) {
            $period = '';
        }

        return [
            $period,
            $request->string('tanggal_from')->toString() ?: null,
            $request->string('tanggal_to')->toString() ?: null,
        ];
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Marking>  $query */
    private function applyMarketingFilter(\Illuminate\Database\Eloquent\Builder $query, int $salesFilterId): void
    {
        $user = auth()->user();
        if (
            Marking::userHasTeamMarkingScope($user)
            && ! in_array($salesFilterId, Marking::teamScopeUserIds($user), true)
        ) {
            return;
        }

        $query->where('sales_id', $salesFilterId);
    }
}
