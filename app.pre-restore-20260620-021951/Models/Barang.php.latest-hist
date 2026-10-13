<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CbmFormat;
use App\Support\MarkingPricingResolver;
use App\Support\RmbFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class Barang extends Model
{
    public const STATUS_DITARIK_KEMBALI = 'ditarik_kembali';

    public const STATUS_STUFFING = 'stuffing';

    /** Legacy status (pre-migration); still loadable into container. */
    public const STATUS_INBOUND = 'inbound';

    public const STATUS_ETD = 'etd';

    public const STATUS_ETA = 'eta';

    public const STATUS_BONGKAR = 'bongkar';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    /** @var list<string> */
    public const CONTAINER_LOADABLE_STATUSES = [
        self::STATUS_STUFFING,
        self::STATUS_INBOUND,
    ];

    /** @var list<string> Barang may be manually withdrawn from these statuses (pre-container). */
    public const STATUSES_WITHDRAWABLE_TO = [
        'draft',
        self::STATUS_INBOUND,
        self::STATUS_STUFFING,
    ];

    /** @var list<string> */
    public const SHIPMENT_STATUSES = [
        self::STATUS_DITARIK_KEMBALI,
        self::STATUS_STUFFING,
        self::STATUS_ETD,
        self::STATUS_ETA,
        self::STATUS_BONGKAR,
    ];

    protected $fillable = [
        'no_index', 'no_po', 'marking', 'supplier_id', 'supplier_chinese',
        'no_resi', 'user_id', 'customer_name', 'gudang_china_id', 'warehouse_origin', 'tanggal_masuk',
        'ctns', 'pcs', 'price',
        'material', 'form', 'material_desc',
        'insurance_value', 'insurance_rate', 'insurance_nominal_standar', 'insurance_nominal_custom',
        'total_cbm', 'total_gross_weight', 'cbm_type', 'rate', 'weight',
        'destination', 'other', 'notes', 'notes_id', 'keterangan',
        'status', 'approval_status', 'approved_by', 'approved_at', 'rejection_note',
        'price_per_cbm', 'cost_price_per_cbm', 'extra_charge', 'company_loyalty_amount',
        'weight_price', 'cost_weight_price', 'weight_threshold_kg',
        'total_invoice', 'invoice_file', 'good_receipt_path', 'tracking_receipt_path', 'tracking_number',
        'packing_id', 'is_preload', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_masuk' => 'date',
            'approved_at' => 'datetime',
            'insurance_value' => 'decimal:2',
            'insurance_rate' => 'decimal:2',
            'total_cbm' => 'decimal:4',
            'total_gross_weight' => 'decimal:2',
            'price_per_cbm' => 'decimal:2',
            'cost_price_per_cbm' => 'decimal:2',
            'extra_charge' => 'decimal:2',
            'company_loyalty_amount' => 'decimal:2',
            'weight_price' => 'decimal:2',
            'cost_weight_price' => 'decimal:2',
            'weight_threshold_kg' => 'decimal:2',
            'price' => 'decimal:2',
            'rate' => 'decimal:2',
            'weight' => 'decimal:2',
            'insurance_nominal_standar' => 'decimal:2',
            'insurance_nominal_custom' => 'decimal:2',
            'is_preload' => 'boolean',
            'no_resi' => 'array',
        ];
    }

    public static function shipmentStatusLabel(string $status): string
    {
        $normalized = self::normalizeShipmentStatus($status);
        $key = 'barang.status_' . $normalized;

        return __($key) !== $key ? __($key) : ucfirst($normalized);
    }

    public static function normalizeShipmentStatus(string $status): string
    {
        $status = trim(strtolower($status));

        return match ($status) {
            'draft', 'inbound', 'loaded' => self::STATUS_STUFFING,
            'shipped', 'in_transit' => self::STATUS_ETD,
            'out_for_delivery', 'arrived' => self::STATUS_ETA,
            'delivered' => self::STATUS_BONGKAR,
            default => $status,
        };
    }

    public static function isBongkarStatus(?string $status): bool
    {
        return self::normalizeShipmentStatus((string) $status) === self::STATUS_BONGKAR;
    }

    public static function isDitarikKembaliStatus(?string $status): bool
    {
        return self::normalizeShipmentStatus((string) $status) === self::STATUS_DITARIK_KEMBALI;
    }

    public static function userCanSetDitarikKembali(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(['super_admin', 'admin_cs', 'supervisor']);
    }

    /** @return list<string> */
    public static function productUploadFileKeys(): array
    {
        return ['photo', 'length_photo', 'width_photo', 'height_photo'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function packing(): BelongsTo
    {
        return $this->belongsTo(MasterPacking::class, 'packing_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gudangChina(): BelongsTo
    {
        return $this->belongsTo(MasterGudangChina::class, 'gudang_china_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(BarangProduct::class)->orderBy('sort_order');
    }

    public function additionalPrices(): HasMany
    {
        return $this->hasMany(BarangAdditionalPrice::class)->orderBy('sort_order');
    }

    public function editRequests(): HasMany
    {
        return $this->hasMany(BarangEditRequest::class);
    }

    public function pendingEditRequest(): HasOne
    {
        return $this->hasOne(BarangEditRequest::class)
            ->where('status', BarangEditRequest::STATUS_PENDING)
            ->latestOfMany();
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(BarangDimension::class);
    }

    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Container::class, 'container_barangs')
            ->withPivot('loaded_at', 'loaded_by')
            ->withTimestamps();
    }

    public function deliveryTrips(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryTrip::class, 'delivery_trip_barang')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function scopeEligibleForDeliveryTrip(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_ETA, self::STATUS_BONGKAR])
            ->whereDoesntHave('deliveryTrips', static function (Builder $tripQuery): void {
                $tripQuery->whereIn('delivery_trips.status', DeliveryTrip::ACTIVE_STATUSES);
            });
    }

    public function scopeContainerLoadable(Builder $query): Builder
    {
        return $query->whereIn('status', self::CONTAINER_LOADABLE_STATUSES);
    }

    public function scopeNotInContainer(Builder $query): Builder
    {
        return $query->whereDoesntHave('containers');
    }

    public function scopeAwaitingContainerLoad(Builder $query): Builder
    {
        return $query->containerLoadable()->notInContainer();
    }

    public function scopeFilterByPricingItemId(Builder $query, mixed $pricingItemId): Builder
    {
        if ($pricingItemId === null || $pricingItemId === '') {
            return $query;
        }

        $ids = is_array($pricingItemId)
            ? array_values(array_filter(array_map(static fn ($id) => (int) $id, $pricingItemId)))
            : [(int) $pricingItemId];

        if ($ids === [] || $ids === [0]) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($ids): void {
            foreach ($ids as $pricingItemIdValue) {
                $item = MasterPricingItem::query()->find($pricingItemIdValue);
                if ($item === null) {
                    continue;
                }

                $normalizedName = MarkingPricingResolver::normalizeKey($item->name);

                $outer->orWhere(function (Builder $sub) use ($item, $normalizedName): void {
                    $sub->where('cbm_type', (string) $item->id)
                        ->orWhere('cbm_type', $item->name)
                        ->orWhereRaw('LOWER(TRIM(cbm_type)) = ?', [$normalizedName]);

                    foreach (['cbm', 'piece', 'rim', 'ton', 'set'] as $legacy) {
                        if (MarkingPricingResolver::legacyCbmTypeAlias($legacy) === $normalizedName) {
                            $sub->orWhere('cbm_type', $legacy);
                        }
                    }
                });
            }
        });
    }

    public function trackingStatuses(): HasMany
    {
        return $this->hasMany(TrackingStatus::class)->orderByDesc('created_at');
    }

    public function latestTracking(): HasOne
    {
        return $this->hasOne(TrackingStatus::class)->latestOfMany();
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BarangTask::class)->orderBy('sort_order');
    }

    public function recalculateTotals(): void
    {
        $products = $this->relationLoaded('products')
            ? $this->products
            : $this->products()->get();

        if ($products->isEmpty()) {
            $dims = $this->relationLoaded('dimensions')
                ? $this->dimensions
                : $this->dimensions()->get();
            $this->total_cbm = $dims->sum('volume_cbm');
            $this->total_gross_weight = $dims->sum('gross_weight');
            $this->save();

            return;
        }

        $this->total_cbm = round((float) $products->sum('volume_cbm'), 4);
        $this->total_gross_weight = $products->sum(fn (BarangProduct $p) => $p->lineGrossWeightKg());
        $this->ctns = (int) $products->sum('ctns');
        $this->pcs = (int) $products->sum(
            fn (BarangProduct $p) => (int) $p->pcs_per_box * max(1, (int) $p->ctns)
        );
        $this->save();
    }

    /** Ringkasan tipe/nama barang untuk tabel & preview. */
    public function itemsSummary(): string
    {
        if ($this->relationLoaded('products') && $this->products->isNotEmpty()) {
            $labels = $this->products
                ->map(static function (BarangProduct $product): string {
                    $name = trim((string) ($product->product_name ?? ''));
                    if ($name !== '') {
                        return $name;
                    }

                    return trim((string) ($product->material ?? ''));
                })
                ->filter(static fn (string $label): bool => $label !== '')
                ->unique()
                ->values();

            if ($labels->isNotEmpty()) {
                return $labels->implode(', ');
            }
        }

        $form = trim((string) ($this->form ?? ''));
        if ($form !== '') {
            return $form;
        }

        $supplier = trim((string) ($this->supplier_chinese ?? ''));
        if ($supplier !== '') {
            return $supplier;
        }

        return '-';
    }

    public function getStatusBadgeAttribute(): string
    {
        return 'badge-status-' . $this->status;
    }

    public function getApprovalStatusBadgeAttribute(): string
    {
        return 'badge-approval-' . ($this->approval_status ?? self::APPROVAL_APPROVED);
    }

    public function getContainerCodeAttribute(): ?string
    {
        return $this->containers->first()?->partai;
    }

    public function displayNoResi(): string
    {
        $resi = $this->no_resi;

        if (is_array($resi)) {
            return collect($resi)
                ->filter(static fn ($value) => is_string($value) && trim($value) !== '')
                ->map(static fn (string $value) => trim($value))
                ->implode(', ');
        }

        if (is_string($resi) && trim($resi) !== '') {
            return trim($resi);
        }

        return '';
    }

    public function hasNoResi(): bool
    {
        return $this->displayNoResi() !== '';
    }

    public function isInContainer(): bool
    {
        if ($this->relationLoaded('containers')) {
            return $this->containers->isNotEmpty();
        }

        if (array_key_exists('is_in_container', $this->attributes)) {
            return (bool) $this->attributes['is_in_container'];
        }

        return $this->containers()->exists();
    }

    public function isContainerLoadable(): bool
    {
        return in_array($this->status, self::CONTAINER_LOADABLE_STATUSES, true)
            && ! $this->isInContainer();
    }

    public function canWithdrawToDitarikKembali(): bool
    {
        if ($this->isInContainer()) {
            return false;
        }

        return in_array(
            self::normalizeShipmentStatus((string) $this->status),
            self::STATUSES_WITHDRAWABLE_TO,
            true,
        );
    }

    /** @param array<string, mixed> $data */
    public function insertBarang(array $data, Request $request): self
    {
        return DB::transaction(function () use ($data, $request): self {
            $now = now();
            $row = self::filterBarangRow($data);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $id = (int) DB::table('barangs')->insertGetId($row);
            $barang = new self();
            $barang->id = $id;
            $barang->exists = true;
            $barang->persistRelations($request);

            return $barang;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBarang(array $data, Request $request): self
    {
        DB::transaction(function () use ($data, $request): void {
            $row = self::filterBarangRow($data);
            $row['updated_at'] = now();
            DB::table('barangs')->where('id', $this->id)->update($row);
            $this->persistRelations($request);
        });

        return $this->refresh();
    }

    /** @return array{invoice_ids: list<int>, snapshot: array<string, mixed>} */
    public function deleteBarang(): array
    {
        $invoiceIds = DB::table('invoice_items')
            ->where('barang_id', $this->id)
            ->distinct()
            ->pluck('invoice_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $snapshot = (array) DB::table('barangs')->where('id', $this->id)->first();

        DB::transaction(function (): void {
            DB::table('invoice_items')->where('barang_id', $this->id)->delete();
            DB::table('container_barangs')->where('barang_id', $this->id)->delete();
            DB::table('barangs')->where('id', $this->id)->delete();
        });

        return [
            'invoice_ids' => $invoiceIds,
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<int, array<string, mixed>> $dimensions */
    public function syncDimensions(array $dimensions): void
    {
        $keepIds = [];
        $now = now();

        foreach ($dimensions as $dim) {
            if (! is_array($dim)) {
                continue;
            }

            $payload = [
                'item_name' => $dim['item_name'] ?? null,
                'panjang' => $dim['panjang'] ?? 0,
                'lebar' => $dim['lebar'] ?? 0,
                'tinggi' => $dim['tinggi'] ?? 0,
                'ctn_count' => (int) ($dim['ctn_count'] ?? 1),
                'count_by' => $dim['count_by'] ?? null,
                'gw_per_ctn' => $dim['gw_per_ctn'] ?? 0,
                'gross_weight' => $dim['gross_weight'] ?? 0,
                'updated_at' => $now,
            ];

            if (! empty($dim['id'])) {
                $updated = DB::table('barang_dimensions')
                    ->where('id', (int) $dim['id'])
                    ->where('barang_id', $this->id)
                    ->update($payload);

                if ($updated) {
                    $keepIds[] = (int) $dim['id'];
                }

                continue;
            }

            $payload['barang_id'] = $this->id;
            $payload['created_at'] = $now;
            $keepIds[] = (int) DB::table('barang_dimensions')->insertGetId($payload);
        }

        $query = DB::table('barang_dimensions')->where('barang_id', $this->id);
        if ($keepIds !== []) {
            $query->whereNotIn('id', $keepIds);
        }
        $query->delete();

        $this->recalculateTotals();
    }

    /** @param array<string, mixed> $data */
    private static function filterBarangRow(array $data): array
    {
        $row = [];
        foreach ((new self())->getFillable() as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $row[$key] = $data[$key];
        }

        if (isset($row['no_resi']) && is_array($row['no_resi'])) {
            $row['no_resi'] = json_encode($row['no_resi'], JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('is_preload', $row)) {
            $row['is_preload'] = $row['is_preload'] ? 1 : 0;
        }

        if (isset($row['tanggal_masuk']) && $row['tanggal_masuk'] instanceof \DateTimeInterface) {
            $row['tanggal_masuk'] = $row['tanggal_masuk']->format('Y-m-d');
        }

        if (isset($row['approved_at']) && $row['approved_at'] instanceof \DateTimeInterface) {
            $row['approved_at'] = $row['approved_at']->format('Y-m-d H:i:s');
        }

        return $row;
    }

    public static function deferAfterInsert(int $barangId, ?int $actorId): void
    {
        self::runAfterHttpResponse(static function () use ($barangId, $actorId): void {
            $barang = self::query()->find($barangId);
            $barang?->afterInsert($actorId);
        });
    }

    /** @param array<string, mixed> $old */
    /** @param array<string, mixed> $new */
    public static function deferAfterUpdate(int $barangId, array $old, array $new): void
    {
        self::runAfterHttpResponse(static function () use ($barangId, $old, $new): void {
            $barang = self::query()->find($barangId);
            $barang?->afterUpdate($old, $new);
        });
    }

    /** @param list<int> $invoiceIds */
    /** @param array<string, mixed> $snapshot */
    public static function deferAfterDelete(array $invoiceIds, array $snapshot): void
    {
        self::runAfterHttpResponse(static function () use ($invoiceIds, $snapshot): void {
            self::afterDelete($invoiceIds, $snapshot);
        });
    }

    private static function runAfterHttpResponse(callable $callback): void
    {
        app()->terminating(static function () use ($callback): void {
            if (! app()->runningInConsole() && function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            $callback();
        });
    }

    public function afterInsert(?int $actorId): void
    {
        ActivityLog::record('Barang created', $this);
        $this->syncStuffingTracking($actorId);

        $invoice = $this->createInvoiceIfNeeded();
        if ($invoice) {
            ActivityLog::record('Invoice generated', $invoice);
        }
    }

    /** @param array<string, mixed> $old */
    /** @param array<string, mixed> $new */
    public function afterUpdate(array $old, array $new): void
    {
        ActivityLog::record('Barang updated', $this, $old, $new);
    }

    /** @param list<int|string> $invoiceIds */
    /** @param array<string, mixed> $snapshot */
    public static function afterDelete(array $invoiceIds, array $snapshot): void
    {
        ActivityLog::record('Barang deleted', null, $snapshot);

        foreach ($invoiceIds as $invoiceId) {
            $id = (int) $invoiceId;
            if (! DB::table('invoices')->where('id', $id)->exists()) {
                continue;
            }

            if (! DB::table('invoice_items')->where('invoice_id', $id)->exists()) {
                DB::table('invoices')->where('id', $id)->delete();

                continue;
            }

            Invoice::query()->find($id)?->recalculate();
        }
    }

    private function createInvoiceIfNeeded(): ?Invoice
    {
        if (! $this->user_id) {
            return null;
        }

        if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
            return null;
        }

        $pricePerCbm = (float) ($this->price_per_cbm ?? 0);
        if ($pricePerCbm <= 0) {
            return null;
        }

        $insuranceAmount = (float) $this->insurance_value * ((float) $this->insurance_rate / 100);
        $dueDate = $this->resolveInvoiceDueDate();
        $now = now();

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => Invoice::generateNumber([$this]),
            'user_id' => $this->user_id,
            'price_per_cbm' => $pricePerCbm,
            'status' => 'draft',
            'due_date' => $dueDate->format('Y-m-d'),
            'created_by' => auth()->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $extra = (float) ($this->extra_charge ?? 0);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $this->id,
            'cbm' => $this->total_cbm,
            'price_per_cbm' => $pricePerCbm,
            'insurance' => $insuranceAmount,
            'extra' => $extra,
            'line_total' => BarangShippingPricing::syncInvoiceItemLineTotal(
                (float) $this->total_cbm,
                $pricePerCbm,
                $insuranceAmount,
                $extra,
                $this,
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $invoice = Invoice::query()->find($invoiceId);
        $invoice?->recalculate();

        return $invoice;
    }

    public function syncStuffingTracking(?int $createdBy = null): ?TrackingStatus
    {
        $location = trim((string) ($this->warehouse_origin ?? ''));
        if ($location === '' && $this->gudang_china_id) {
            $location = trim((string) DB::table('master_gudang_china')
                ->where('id', $this->gudang_china_id)
                ->value('name'));
        }
        if ($location === '') {
            $location = (string) __('tracking.auto_stuffing_location_fallback');
        }

        $description = (string) __('tracking.auto_stuffing_description');

        if (DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->where('description', $description)
            ->exists()) {
            return null;
        }

        $now = now();
        $id = (int) DB::table('tracking_statuses')->insertGetId([
            'barang_id' => $this->id,
            'status' => self::STATUS_STUFFING,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TrackingStatus::query()->find($id);
    }

    public function syncContainerLoadedTracking(Container $container, ?int $createdBy = null): ?TrackingStatus
    {
        $location = null;
        if ($container->gudang_china_id) {
            $location = DB::table('master_gudang_chinas')
                ->where('id', $container->gudang_china_id)
                ->value('name');
        }
        $location = $location
            ?? $container->alamat_gudang_china
            ?? __('tracking.auto_stuffing_location_fallback');

        $containerLabel = $container->partai ?: ($container->container_no ?: '#'.$container->id);
        $description = (string) __('tracking.auto_container_loaded_description', ['container' => $containerLabel]);

        if (DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->where('description', $description)
            ->exists()) {
            return null;
        }

        $now = now();
        $id = (int) DB::table('tracking_statuses')->insertGetId([
            'barang_id' => $this->id,
            'status' => self::STATUS_STUFFING,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TrackingStatus::query()->find($id);
    }

    public function syncEtdTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncAutomaticTrackingStatus(
            self::STATUS_ETD,
            $location,
            $description ?? (string) __('tracking.auto_etd_description'),
            $createdBy,
        );
    }

    public function syncEtaTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncAutomaticTrackingStatus(
            self::STATUS_ETA,
            $location,
            $description ?? (string) __('tracking.auto_eta_description'),
            $createdBy,
        );
    }

    public function syncBongkarTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncAutomaticTrackingStatus(
            self::STATUS_BONGKAR,
            $location,
            $description ?? (string) __('tracking.auto_bongkar_description'),
            $createdBy,
        );
    }

    private function syncAutomaticTrackingStatus(
        string $status,
        ?string $location,
        string $description,
        ?int $createdBy,
    ): ?TrackingStatus {
        $location = trim((string) ($location ?? ''));
        if ($location === '') {
            $location = trim((string) ($this->destination ?? ''));
        }
        if ($location === '') {
            $location = (string) __('tracking.auto_stuffing_location_fallback');
        }

        if (DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->where('status', $status)
            ->where('description', $description)
            ->exists()) {
            return null;
        }

        $now = now();
        $id = (int) DB::table('tracking_statuses')->insertGetId([
            'barang_id' => $this->id,
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TrackingStatus::query()->find($id);
    }

    private function resolveInvoiceDueDate(): Carbon
    {
        $days = 7;
        $markingValue = trim((string) ($this->marking ?? ''));

        if ($markingValue !== '') {
            $marking = Marking::query()
                ->whereBarangMarkingValue($markingValue)
                ->active()
                ->first(['id', 'invoice_due_days']);

            if ($marking !== null) {
                $days = (int) ($marking->invoice_due_days ?? 0);
                if ($days <= 0 && Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
                    $fromPricings = (int) DB::table('marking_pricings')
                        ->where('marking_id', $marking->id)
                        ->max('invoice_due_days');
                    if ($fromPricings > 0) {
                        $days = $fromPricings;
                    }
                }
                $days = max(0, $days);
            }
        }

        return $days <= 0
            ? today()->startOfDay()
            : today()->addDays($days)->startOfDay();
    }

    private function persistRelations(Request $request): void
    {
        $this->syncProducts($request);
        $this->syncAdditionalPrices($request);
        $this->recalculateTotals();
    }

    /** @param array<string, mixed> $row */
    public static function isSubProductInputRow(array $row): bool
    {
        return filter_var($row['is_sub_item'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ! empty($row['parent_id']);
    }

    /** @param array<string, mixed> $row */
    public static function isEmptyProductInputRow(array $row): bool
    {
        if (self::isSubProductInputRow($row)) {
            if (! empty(trim((string) ($row['product_name'] ?? '')))) {
                return false;
            }

            if ((int) ($row['pcs_per_box'] ?? 0) > 0) {
                return false;
            }

            if ((float) ($row['price_per_unit_rmb'] ?? 0) > 0) {
                return false;
            }

            return empty($row['id']);
        }

        foreach (['product_name', 'material'] as $field) {
            if (! empty(trim((string) ($row[$field] ?? '')))) {
                return false;
            }
        }

        foreach (['ctns', 'pcs_per_box', 'gross_weight_kg', 'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb'] as $field) {
            if ((float) ($row[$field] ?? 0) > 0) {
                return false;
            }
        }

        return empty($row['id']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $parentRow
     * @return array<string, mixed>
     */
    private static function productPayloadFromInputRow(array $row, ?array $parentRow, bool $isSub): array
    {
        if ($isSub) {
            $parentRow ??= [];

            return [
                'product_name' => $row['product_name'] ?? null,
                'material' => null,
                'pcs_per_box' => (int) ($row['pcs_per_box'] ?? 0),
                'ctns' => 0,
                'gross_weight_kg' => 0,
                'length_cm' => 0,
                'width_cm' => 0,
                'height_cm' => 0,
                'price_per_unit_rmb' => (float) ($row['price_per_unit_rmb'] ?? 0),
                'total_price_rmb' => (float) ($row['total_price_rmb'] ?? 0),
            ];
        }

        return [
            'product_name' => $row['product_name'] ?? null,
            'material' => $row['material'] ?? null,
            'pcs_per_box' => (int) ($row['pcs_per_box'] ?? 0),
            'ctns' => (int) ($row['ctns'] ?? 0),
            'gross_weight_kg' => $row['gross_weight_kg'] ?? 0,
            'length_cm' => $row['length_cm'] ?? 0,
            'width_cm' => $row['width_cm'] ?? 0,
            'height_cm' => $row['height_cm'] ?? 0,
            'price_per_unit_rmb' => $row['price_per_unit_rmb'] ?? 0,
            'total_price_rmb' => $row['total_price_rmb'] ?? 0,
        ];
    }

    private function syncAdditionalPrices(Request $request): void
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
            ? DB::table('barang_additional_prices')->where('barang_id', $this->id)->get()->keyBy('id')
            : collect();

        $keepIds = [];
        $sort = 0;
        $now = now();

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
                'updated_at' => $now,
            ];

            $record = ! empty($row['id']) ? $existing->get((int) $row['id']) : null;

            if ($record) {
                if ($this->additionalPriceChanged($record, $payload)) {
                    DB::table('barang_additional_prices')
                        ->where('id', (int) $record->id)
                        ->where('barang_id', $this->id)
                        ->update($payload);
                }
                $keepIds[] = (int) $record->id;
            } else {
                $payload['barang_id'] = $this->id;
                $payload['created_at'] = $now;
                $keepIds[] = (int) DB::table('barang_additional_prices')->insertGetId($payload);
            }
        }

        if ($existing->isNotEmpty()) {
            DB::table('barang_additional_prices')
                ->where('barang_id', $this->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }

    private function syncProducts(Request $request): void
    {
        if (! $request->has('products')) {
            return;
        }

        $rows = $request->input('products', []);
        if (! is_array($rows)) {
            return;
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
            if ($this->bulkInsertProducts($rows)) {
                return;
            }
        }

        $this->syncProductsIteratively($request, $rows, $hasExistingIds);
    }

    /** @param array<int, mixed> $rows */
    private function bulkInsertProducts(array $rows): bool
    {
        $now = now();
        /** @var list<array<string, mixed>> $pending */
        $pending = [];
        $sort = 0;
        $lastParentInput = null;
        $lastParentQueueIndex = null;

        foreach ($rows as $row) {
            if (! is_array($row) || self::isEmptyProductInputRow($row)) {
                continue;
            }

            $isSub = self::isSubProductInputRow($row);
            if ($isSub && $lastParentInput === null) {
                continue;
            }

            if (! $isSub) {
                $lastParentInput = $row;
            }

            $payload = self::applyProductCalculations(array_merge(
                self::productPayloadFromInputRow($row, $lastParentInput, $isSub),
                [
                    'barang_id' => $this->id,
                    'parent_id' => null,
                    'sort_order' => $sort++,
                    'photo_path' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ));

            $queueIndex = count($pending);
            $pending[] = $payload;

            if ($isSub && $lastParentQueueIndex !== null) {
                $pending[$queueIndex]['_parent_queue_index'] = $lastParentQueueIndex;
            } else {
                $lastParentQueueIndex = $queueIndex;
            }
        }

        if ($pending === []) {
            return false;
        }

        $parentIdByQueueIndex = [];
        foreach ($pending as $queueIndex => $payload) {
            $parentQueueIndex = $payload['_parent_queue_index'] ?? null;
            unset($payload['_parent_queue_index']);

            if ($parentQueueIndex !== null && isset($parentIdByQueueIndex[$parentQueueIndex])) {
                $payload['parent_id'] = $parentIdByQueueIndex[$parentQueueIndex];
            }

            $parentIdByQueueIndex[$queueIndex] = (int) DB::table('barang_products')->insertGetId($payload);
        }

        return true;
    }

    /** @param array<int, mixed> $rows */
    private function syncProductsIteratively(Request $request, array $rows, bool $hasExistingIds): void
    {
        $existing = $hasExistingIds
            ? DB::table('barang_products')->where('barang_id', $this->id)->get()->keyBy('id')
            : collect();

        $keepIds = [];
        $sort = 0;
        $now = now();
        $lastParentInput = null;
        $lastParentId = null;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (self::isEmptyProductInputRow($row) && ! $request->hasFile("products.{$index}.photo")) {
                continue;
            }

            $isSub = self::isSubProductInputRow($row);
            if ($isSub && $lastParentId === null) {
                continue;
            }

            if (! $isSub) {
                $lastParentInput = $row;
            }

            $product = ! empty($row['id']) ? $existing->get((int) $row['id']) : null;

            $payload = self::applyProductCalculations(array_merge(
                self::productPayloadFromInputRow($row, $lastParentInput, $isSub),
                [
                    'sort_order' => $sort++,
                    'parent_id' => $isSub ? $lastParentId : null,
                ],
            ));

            $file = $request->file("products.{$index}.photo");
            if ($file) {
                if ($product?->photo_path) {
                    Storage::disk('public')->delete($product->photo_path);
                }
                $payload['photo_path'] = $file->store('barang-products', 'public');
            } elseif ($request->boolean("products.{$index}.remove_photo") && $product?->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
                $payload['photo_path'] = null;
            }

            if ($product) {
                $hasNewPhoto = array_key_exists('photo_path', $payload);
                if ($hasNewPhoto || $this->productChanged($product, $payload)) {
                    $payload['updated_at'] = $now;
                    DB::table('barang_products')
                        ->where('id', (int) $product->id)
                        ->where('barang_id', $this->id)
                        ->update($payload);
                }
                $savedId = (int) $product->id;
                $keepIds[] = $savedId;
            } else {
                $payload['barang_id'] = $this->id;
                $payload['created_at'] = $now;
                $payload['updated_at'] = $now;
                $savedId = (int) DB::table('barang_products')->insertGetId($payload);
                $keepIds[] = $savedId;
            }

            if (! $isSub) {
                $lastParentId = $savedId;
            }
        }

        if ($existing->isNotEmpty()) {
            DB::table('barang_products')
                ->where('barang_id', $this->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private static function applyProductCalculations(array $payload): array
    {
        $isSub = ! empty($payload['parent_id']) || ((int) ($payload['ctns'] ?? 0) === 0 && (int) ($payload['pcs_per_box'] ?? 0) > 0);

        if ($isSub) {
            $pcs = max(0, (int) ($payload['pcs_per_box'] ?? 0));
            $unitPrice = RmbFormat::round((float) ($payload['price_per_unit_rmb'] ?? 0));
            $payload['price_per_unit_rmb'] = $unitPrice;
            $payload['volume_cbm'] = 0;
            $payload['total_price_rmb'] = ($unitPrice > 0 && $pcs > 0)
                ? RmbFormat::lineTotal($unitPrice, $pcs)
                : (float) ($payload['total_price_rmb'] ?? 0);

            return $payload;
        }

        $ctns = max(0, (int) ($payload['ctns'] ?? 0));
        $length = (float) ($payload['length_cm'] ?? 0);
        $width = (float) ($payload['width_cm'] ?? 0);
        $height = (float) ($payload['height_cm'] ?? 0);

        $payload['volume_cbm'] = CbmFormat::truncate(
            ($length * $width * $height * $ctns) / 1_000_000,
        );

        $pcs = max(0, (int) ($payload['pcs_per_box'] ?? 0));
        $unitPrice = RmbFormat::round((float) ($payload['price_per_unit_rmb'] ?? 0));
        $payload['price_per_unit_rmb'] = $unitPrice;

        if ($unitPrice > 0 && $pcs > 0 && $ctns > 0) {
            $payload['total_price_rmb'] = RmbFormat::lineTotal($unitPrice, $pcs, $ctns);
        } elseif ($unitPrice > 0 && $pcs > 0) {
            $payload['total_price_rmb'] = RmbFormat::lineTotal($unitPrice, $pcs);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function additionalPriceChanged(object $record, array $payload): bool
    {
        return (int) $record->sort_order !== (int) ($payload['sort_order'] ?? 0)
            || trim((string) $record->name) !== trim((string) ($payload['name'] ?? ''))
            || abs((float) $record->price - (float) ($payload['price'] ?? 0)) > 0.0001;
    }

    /** @param array<string, mixed> $payload */
    private function productChanged(object $product, array $payload): bool
    {
        if (array_key_exists('photo_path', $payload)) {
            return true;
        }

        foreach (['product_name', 'material'] as $field) {
            if (trim((string) $product->{$field}) !== trim((string) ($payload[$field] ?? ''))) {
                return true;
            }
        }

        if ((int) ($product->parent_id ?? 0) !== (int) ($payload['parent_id'] ?? 0)) {
            return true;
        }

        foreach (['sort_order', 'ctns', 'pcs_per_box'] as $field) {
            if ((int) $product->{$field} !== (int) ($payload[$field] ?? 0)) {
                return true;
            }
        }

        foreach (['gross_weight_kg', 'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb'] as $field) {
            if (abs((float) $product->{$field} - (float) ($payload[$field] ?? 0)) > 0.0001) {
                return true;
            }
        }

        return false;
    }

}
