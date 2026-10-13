<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BarangShippingPricing;
use App\Support\CbmFormat;
use App\Support\CbmVolume;
use App\Support\CompanyRevenueSettings;
use App\Support\DatePresetFilter;
use App\Support\InvoiceDueResolver;
use App\Support\InvoiceStatusPresenter;
use App\Support\MarkingPricingResolver;
use App\Support\TrackingEventDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class Barang extends Model
{
    protected static function booted(): void
    {
        static::updated(static function (self $barang): void {
            if ($barang->wasChanged('status')) {
                $previous = self::normalizeShipmentStatus((string) $barang->getOriginal('status'));
                $current = self::normalizeShipmentStatus((string) $barang->status);

                if (self::transitionedToBongkar($previous, $current)) {
                    $barang->recordInvoiceIfBongkar($previous);
                }

                if (self::transitionedToWithdrawn($previous, $current)) {
                    $barang->releaseDraftInvoicesOnWithdraw();
                }

                if (self::transitionedFromWithdrawnToEligible($previous, $current)) {
                    $barang->recordInvoiceOnMasuk();
                }
            }

            if (
                $barang->wasChanged('price_per_cbm')
                && self::isAutoInvoiceEligibleStatus((string) $barang->status)
                && (float) ($barang->price_per_cbm ?? 0) > 0
            ) {
                if (self::isBongkarStatus((string) $barang->status)) {
                    $barang->recordInvoiceIfBongkar();
                } else {
                    $barang->recordInvoiceOnMasuk();
                }
            }

            // Instant pricing / edit harga: push ke invoice draft/sent yang belum ada pembayaran approved.
            if ($barang->wasChanged(['price_per_cbm', 'extra_charge', 'weight_price'])) {
                $barang->syncOpenInvoicePricingFromBarang();
            }
        });
    }
    protected $fillable = [
        'no_index', 'no_po', 'marking', 'supplier_id', 'supplier_chinese',
        'no_resi', 'tracking_number', 'tracking_receipt_path', 'good_receipt_path',
        'user_id', 'customer_name', 'gudang_china_id', 'warehouse_origin', 'tanggal_masuk',
        'ctns', 'pcs', 'price',
        'form',
        'insurance_value', 'insurance_rate', 'insurance_nominal_standar', 'insurance_nominal_custom',
        'total_cbm', 'total_gross_weight', 'cbm_type', 'nama_barang', 'rate',
        'destination', 'other', 'packing_id', 'notes', 'notes_id', 'keterangan',
        'status', 'approval_status', 'approved_by', 'approved_at', 'rejection_note',
        'price_per_cbm', 'cost_price_per_cbm', 'extra_charge', 'company_loyalty_amount',
        'total_invoice', 'invoice_file', 'weight_price', 'cost_weight_price', 'weight_threshold_kg',
        'minimum_charge_cbm',
        'is_preload', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_masuk' => 'date',
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
            'minimum_charge_cbm' => 'decimal:2',
            'price' => 'decimal:3',
            'rate' => 'decimal:2',
            'insurance_nominal_standar' => 'decimal:2',
            'insurance_nominal_custom' => 'decimal:2',
            'is_preload' => 'boolean',
            'no_resi' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * List date filter: always {@see $tanggal_masuk}, never created_at.
     * Rows with null tanggal_masuk are excluded when a date range is active.
     */
    public function scopeFilterByTanggalMasukPreset(
        Builder $query,
        ?string $preset,
        ?string $from = null,
        ?string $to = null,
    ): Builder {
        $preset = DatePresetFilter::normalizePreset($preset);
        if ($preset === '' && ($from !== null && $from !== '' || $to !== null && $to !== '')) {
            $preset = 'custom';
        }

        [$fromDate, $toDate] = DatePresetFilter::resolveBounds($preset, $from, $to);

        return $query->filterByTanggalMasukRange(
            self::normalizeTanggalMasukBound($fromDate),
            self::normalizeTanggalMasukBound($toDate, true),
        );
    }

    /**
     * @param  Carbon|string|null  $from
     * @param  Carbon|string|null  $to
     */
    public function scopeFilterByTanggalMasukRange(Builder $query, mixed $from = null, mixed $to = null): Builder
    {
        $from = self::normalizeTanggalMasukBound($from);
        $to = self::normalizeTanggalMasukBound($to, true);

        if ($from === null && $to === null) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn('tanggal_masuk');

        if ($from !== null && $to !== null) {
            return $query->whereBetween($column, [$from->toDateString(), $to->toDateString()]);
        }

        if ($from !== null) {
            return $query->whereDate($column, '>=', $from->toDateString());
        }

        if ($to !== null) {
            return $query->whereDate($column, '<=', $to->toDateString());
        }

        return $query;
    }

    public function scopeFilterByPricingItemId(Builder $query, mixed $pricingItemId): Builder
    {
        $ids = self::normalizePricingItemFilterInput($pricingItemId);
        if ($ids === []) {
            return $query;
        }

        $items = MasterPricingItem::query()->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($items): void {
            foreach ($items as $item) {
                $outer->orWhere(function (Builder $sub) use ($item): void {
                    self::applyPricingItemCbmTypeConstraint($sub, $item);
                });
            }
        });
    }

    /**
     * @return list<int>
     */
    private static function normalizePricingItemFilterInput(mixed $input): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }

        $candidates = is_array($input) ? $input : [$input];
        if (
            count($candidates) === 1
            && is_string($candidates[0])
            && str_contains($candidates[0], ',')
        ) {
            $candidates = explode(',', $candidates[0]);
        }

        $ids = [];
        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private static function applyPricingItemCbmTypeConstraint(Builder $sub, MasterPricingItem $item): void
    {
        $normalizedName = MarkingPricingResolver::normalizeKey($item->name);

        $sub->where('cbm_type', (string) $item->id)
            ->orWhere('cbm_type', $item->name)
            ->orWhereRaw('LOWER(TRIM(cbm_type)) = ?', [$normalizedName]);

        foreach (['cbm', 'piece', 'rim', 'ton', 'set'] as $legacy) {
            if (MarkingPricingResolver::legacyCbmTypeAlias($legacy) === $normalizedName) {
                $sub->orWhere('cbm_type', $legacy);
            }
        }
    }

    private static function normalizeTanggalMasukBound(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse((string) $value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    public const STATUS_DITARIK_KEMBALI = 'ditarik_kembali';
    public const STATUS_STUFFING = 'stuffing';

    /** Legacy status (pre-migration); still loadable into container. */
    public const STATUS_INBOUND = 'inbound';

    public const STATUS_ETD = 'etd';
    public const STATUS_ETA = 'eta';
    public const STATUS_BONGKAR = 'bongkar';

    public static function normalizeShipmentStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    public static function isBongkarStatus(?string $status): bool
    {
        return self::normalizeShipmentStatus((string) $status) === self::STATUS_BONGKAR;
    }

    /** Barang yang boleh auto-invoice (masuk gudang s/d bongkar, bukan ditarik). */
    public static function isAutoInvoiceEligibleStatus(?string $status): bool
    {
        return in_array(
            self::normalizeShipmentStatus((string) $status),
            self::AUTO_INVOICE_ELIGIBLE_STATUSES,
            true,
        );
    }

    public static function isDitarikKembaliStatus(?string $status): bool
    {
        return self::normalizeShipmentStatus((string) $status) === self::STATUS_DITARIK_KEMBALI;
    }

    public static function userCanSetDitarikKembali(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(['super_admin', 'admin_cs', 'supervisor']);
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

    public static function transitionedToBongkar(?string $previousStatus, ?string $newStatus): bool
    {
        return self::isBongkarStatus($newStatus)
            && ! self::isBongkarStatus($previousStatus);
    }

    public static function transitionedToWithdrawn(?string $previousStatus, ?string $newStatus): bool
    {
        return self::isDitarikKembaliStatus($newStatus)
            && ! self::isDitarikKembaliStatus($previousStatus);
    }

    public static function transitionedFromWithdrawnToEligible(?string $previousStatus, ?string $newStatus): bool
    {
        return self::isDitarikKembaliStatus($previousStatus)
            && self::isAutoInvoiceEligibleStatus($newStatus);
    }

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

    /** @var list<string> Status yang memicu auto-invoice (masuk barang → bongkar). */
    public const AUTO_INVOICE_ELIGIBLE_STATUSES = [
        self::STATUS_STUFFING,
        self::STATUS_INBOUND,
        self::STATUS_ETD,
        self::STATUS_ETA,
        self::STATUS_BONGKAR,
    ];

    /** Tampilan no. resi (JSON array atau string legacy). */
    public function getWeightThresholdKgAttribute(mixed $value): int
    {
        return (int) BarangShippingPricing::normalizeThresholdPerCbmKg((float) $value);
    }

    public function setWeightThresholdKgAttribute(mixed $value): void
    {
        $this->attributes['weight_threshold_kg'] = (int) BarangShippingPricing::normalizeThresholdPerCbmKg((float) $value);
    }

    /** Tampilan no. resi (JSON array atau string legacy). */
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
        return $this->listResiLines() !== [];
    }

    /**
     * Tracking number plus distinct entries from no_resi (for list/export display).
     *
     * @return list<string>
     */
    public function listResiLines(): array
    {
        $lines = [];
        $tracking = trim((string) ($this->tracking_number ?? ''));
        if ($tracking !== '') {
            $lines[] = $tracking;
        }

        $resi = $this->no_resi;
        if (is_array($resi)) {
            foreach ($resi as $item) {
                $item = trim((string) $item);
                if ($item !== '' && ! in_array($item, $lines, true)) {
                    $lines[] = $item;
                }
            }
        } elseif (is_string($resi) && trim($resi) !== '') {
            $item = trim($resi);
            if (! in_array($item, $lines, true)) {
                $lines[] = $item;
            }
        }

        return $lines;
    }

    public function scopeContainerLoadable(Builder $query): Builder
    {
        return $query->whereIn('status', self::CONTAINER_LOADABLE_STATUSES);
    }

    /** Barang not linked on container_barangs (belum dimuat ke container). */
    public function scopeNotInContainer(Builder $query): Builder
    {
        return $query->whereDoesntHave('containers');
    }

    /**
     * Stuffing / inbound yang masih antre muat: status loadable dan belum punya relasi container.
     */
    public function scopeAwaitingContainerLoad(Builder $query): Builder
    {
        return $query->containerLoadable()->notInContainer();
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

    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    public static function shipmentStatusLabel(string $status): string
    {
        $key = 'barang.status_' . $status;

        return __($key) !== $key ? __($key) : ucfirst($status);
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

    public function gudangChina(): BelongsTo
    {
        return $this->belongsTo(MasterGudangChina::class, 'gudang_china_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApprovalPending(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function isApprovalApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isApprovalRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_REJECTED;
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(BarangDimension::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BarangProduct::class)->orderBy('sort_order');
    }

    /** Ringkasan tipe/nama barang untuk tabel & preview. */
    public function itemsSummary(): string
    {
        if ($this->relationLoaded('products') && $this->products->isNotEmpty()) {
            $allProducts = $this->products;
            $parents = $allProducts->whereNull('parent_id');
            if ($parents->isEmpty()) {
                $parents = $allProducts;
            }

            $labels = collect();
            foreach ($parents as $parent) {
                if (! $parent instanceof BarangProduct) {
                    continue;
                }

                $name = trim((string) ($parent->product_name ?? ''));
                if ($name === '') {
                    $name = trim((string) ($parent->material ?? ''));
                }

                if ($name !== '') {
                    $labels->push($name);
                    continue;
                }

                $parentKey = (int) $parent->getKey();
                $subLabels = $allProducts
                    ->filter(static fn (BarangProduct $row): bool => (int) $row->parent_id === $parentKey)
                    ->map(static function (BarangProduct $sub): string {
                        $subName = trim((string) ($sub->product_name ?? ''));
                        if ($subName !== '') {
                            return $subName;
                        }

                        return trim((string) ($sub->material ?? ''));
                    })
                    ->filter(static fn (string $label): bool => $label !== '');

                $labels = $labels->merge($subLabels);
            }

            $labels = $labels->unique()->values();

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

    /**
     * Whether daftar produk is filled enough for operations (Today Task hint).
     * Parent rows need name, CTNS, dimensions, GW, and photo; sub-items inherit parent logistics.
     */
    public function hasCompleteProductList(): bool
    {
        $products = $this->relationLoaded('products')
            ? $this->products
            : $this->products()->get();

        $parents = $products->whereNull('parent_id');

        if ($parents->isEmpty()) {
            return false;
        }

        foreach ($parents as $parent) {
            if (! $parent instanceof BarangProduct) {
                return false;
            }

            $parentKey = (int) $parent->getKey();
            $hasSubs = $parentKey > 0
                && $products->contains(
                    static fn (BarangProduct $row): bool => (int) $row->parent_id === $parentKey,
                );

            if (! self::isCompleteParentProduct($parent, $hasSubs)) {
                return false;
            }
        }

        foreach ($products->whereNotNull('parent_id') as $sub) {
            if (! $sub instanceof BarangProduct || ! self::isCompleteSubProduct($sub)) {
                return false;
            }
        }

        return true;
    }

    private static function isCompleteParentProduct(BarangProduct $product, bool $hasSubs = false): bool
    {
        if (! $hasSubs && trim((string) ($product->product_name ?? '')) === '') {
            return false;
        }

        if (! $hasSubs && $product->normalizedPhotoPath() === null) {
            return false;
        }

        if ((int) $product->ctns <= 0) {
            return false;
        }

        if ((float) $product->gross_weight_kg <= 0) {
            return false;
        }

        foreach (['length_cm', 'width_cm', 'height_cm'] as $field) {
            if ((float) ($product->{$field} ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }

    private static function isCompleteSubProduct(BarangProduct $product): bool
    {
        return trim((string) ($product->product_name ?? '')) !== '';
    }

    public function additionalPrices(): HasMany
    {
        return $this->hasMany(BarangAdditionalPrice::class)->orderBy('sort_order');
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
            ->where(static function (Builder $eligibleQuery): void {
                $eligibleQuery
                    ->whereIn('status', [self::STATUS_ETA, self::STATUS_BONGKAR])
                    ->orWhereHas('containers', static function (Builder $containerQuery): void {
                        $containerQuery->whereIn('status', [
                            Container::STATUS_UNLOADING,
                            Container::STATUS_CLOSED,
                        ]);
                    });
            })
            ->whereDoesntHave('deliveryTrips', static function (Builder $tripQuery): void {
                $tripQuery->whereIn('delivery_trips.status', DeliveryTrip::ACTIVE_STATUSES);
            });
    }

    public function trackingStatuses(): HasMany
    {
        $relation = $this->hasMany(TrackingStatus::class);
        if (TrackingEventDate::barangColumnReady()) {
            return $relation
                ->orderByRaw('COALESCE(event_date, DATE(created_at)) DESC')
                ->orderByDesc('id');
        }

        return $relation->orderByDesc('created_at');
    }

    public function latestTracking(): HasOne
    {
        if (TrackingEventDate::barangColumnReady()) {
            return $this->hasOne(TrackingStatus::class)->ofMany([
                'event_date' => 'max',
                'id' => 'max',
            ]);
        }

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

    public function repricingRequests(): HasMany
    {
        return $this->hasMany(RepricingRequest::class);
    }

    public function pendingRepricingRequest(): HasOne
    {
        return $this->hasOne(RepricingRequest::class)
            ->where('status', RepricingRequest::STATUS_PENDING)
            ->latestOfMany();
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

    public function tasks(): HasMany
    {
        return $this->hasMany(BarangTask::class)->orderBy('sort_order');
    }

    /**
     * Totals aggregate from product rows (CBM, GW, CTNS, PCS).
     * Legacy barang without products keep existing stored totals.
     */
    public function recalculateTotals(): void
    {
        $parents = DB::table('barang_products')
            ->where('barang_id', $this->id)
            ->whereNull('parent_id')
            ->get(['id', 'length_cm', 'width_cm', 'height_cm', 'ctns', 'gross_weight_kg', 'pcs_per_box']);

        if ($parents->isEmpty()) {
            return;
        }

        $previousTotalCbm = (float) ($this->total_cbm ?? 0);
        $now = now();
        $lineVolumes = [];
        $totalGw = 0.0;
        $totalCtns = 0;
        $totalPcs = 0;

        foreach ($parents as $row) {
            $ctns = max(0, (int) $row->ctns);
            $volumeCbm = \App\Support\CbmFormat::truncate(
                \App\Support\CbmVolume::rawLine(
                    (float) $row->length_cm,
                    (float) $row->width_cm,
                    (float) $row->height_cm,
                    $ctns,
                ),
            );
            $lineVolumes[] = $volumeCbm;
            $totalGw += (float) $row->gross_weight_kg * max(1, $ctns);
            $totalCtns += $ctns;
            $totalPcs += (int) $row->pcs_per_box * max(1, $ctns);

            DB::table('barang_products')
                ->where('id', (int) $row->id)
                ->where('barang_id', $this->id)
                ->update([
                    'volume_cbm' => $volumeCbm,
                    'updated_at' => $now,
                ]);
        }

        // Total harus = jumlah CBM per baris (yang juga ditampilkan di form), bukan jalur raw terpisah.
        $totalCbm = \App\Support\CbmFormat::truncate(array_sum($lineVolumes));

        DB::table('barangs')->where('id', $this->id)->update([
            'total_cbm' => $totalCbm,
            'total_gross_weight' => $totalGw,
            'ctns' => $totalCtns,
            'pcs' => $totalPcs,
            'updated_at' => now(),
        ]);

        $this->total_cbm = $totalCbm;
        $this->total_gross_weight = $totalGw;
        $this->ctns = $totalCtns;
        $this->pcs = $totalPcs;

        if (abs($previousTotalCbm - $totalCbm) > 0.0005 || $this->openInvoiceCbmOutOfSync()) {
            $this->syncOpenInvoiceItemsFromTotals();
        }
    }

    /**
     * CBM yang ditampilkan ke user/PDF: jumlah CBM per produk (truncate per baris).
     * Fallback ke kolom total_cbm jika belum ada produk.
     */
    public function displayTotalCbm(): float
    {
        $parents = $this->relationLoaded('products')
            ? $this->products->filter(static fn (BarangProduct $p): bool => $p->parent_id === null)->values()
            : null;

        if ($parents === null) {
            $parents = $this->products()->whereNull('parent_id')->get();
        }

        if ($parents->isEmpty()) {
            return CbmFormat::truncate((float) ($this->total_cbm ?? 0));
        }

        $sum = 0.0;
        foreach ($parents as $product) {
            $sum += CbmFormat::truncate(
                CbmVolume::rawLine(
                    (float) $product->length_cm,
                    (float) $product->width_cm,
                    (float) $product->height_cm,
                    max(0, (int) $product->ctns),
                ),
            );
        }

        return CbmFormat::truncate($sum);
    }

    private function openInvoiceCbmOutOfSync(): bool
    {
        return DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('ii.barang_id', $this->id)
            ->whereIn('i.status', ['draft', 'sent'])
            ->whereRaw('ABS(ii.cbm - ?) > 0.0005', [(float) $this->total_cbm])
            ->exists();
    }

    /**
     * Keep draft/sent invoice lines aligned with barang.total_cbm after product edits.
     * Fully paid invoices (ada pembayaran approved) dibiarkan.
     */
    public function syncOpenInvoiceItemsFromTotals(): void
    {
        $items = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('ii.barang_id', $this->id)
            ->whereIn('i.status', ['draft', 'sent'])
            ->select(['ii.id', 'ii.invoice_id', 'ii.cbm', 'ii.price_per_cbm', 'ii.insurance', 'ii.extra'])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $this->loadMissing('additionalPrices');
        $invoiceIds = [];
        $now = now();

        foreach ($items as $row) {
            if (abs((float) $row->cbm - (float) $this->total_cbm) <= 0.0005) {
                continue;
            }

            $invoice = Invoice::query()->with('payments')->find((int) $row->invoice_id);
            if ($invoice === null) {
                continue;
            }

            $paid = (float) $invoice->payments->where('status', 'approved')->sum('amount');
            if ($paid > 0.009) {
                continue;
            }

            $lineTotal = BarangShippingPricing::syncInvoiceItemLineTotal(
                (float) $this->total_cbm,
                (float) $row->price_per_cbm,
                (float) $row->insurance,
                (float) $row->extra,
                $this,
            );

            DB::table('invoice_items')->where('id', (int) $row->id)->update([
                'cbm' => $this->total_cbm,
                'line_total' => $lineTotal,
                'updated_at' => $now,
            ]);

            $invoiceIds[(int) $row->invoice_id] = true;
        }

        foreach (array_keys($invoiceIds) as $invoiceId) {
            Invoice::query()->find($invoiceId)?->recalculate();
        }
    }

    /**
     * Push harga jual / extra charge barang ke baris invoice draft|sent (belum ada pembayaran approved).
     * Dipakai setelah Instant Pricing di-ACC supaya PDF & grand_total ikut berubah.
     */
    public function syncOpenInvoicePricingFromBarang(): void
    {
        $items = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('ii.barang_id', $this->id)
            ->whereIn('i.status', ['draft', 'sent'])
            ->select(['ii.id', 'ii.invoice_id', 'ii.cbm', 'ii.price_per_cbm', 'ii.insurance', 'ii.extra'])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $this->loadMissing('additionalPrices');
        $invoiceIds = [];
        $now = now();
        $newPrice = (float) ($this->price_per_cbm ?? 0);
        $newExtra = (float) ($this->extra_charge ?? 0);
        $newCbm = (float) ($this->total_cbm ?? 0);

        foreach ($items as $row) {
            $invoice = Invoice::query()->with('payments')->find((int) $row->invoice_id);
            if ($invoice === null) {
                continue;
            }

            $paid = (float) $invoice->payments->where('status', 'approved')->sum('amount');
            if ($paid > 0.009) {
                continue;
            }

            $lineTotal = BarangShippingPricing::syncInvoiceItemLineTotal(
                $newCbm,
                $newPrice,
                (float) $row->insurance,
                $newExtra,
                $this,
            );

            DB::table('invoice_items')->where('id', (int) $row->id)->update([
                'cbm' => $newCbm,
                'price_per_cbm' => $newPrice,
                'extra' => $newExtra,
                'line_total' => $lineTotal,
                'updated_at' => $now,
            ]);

            $invoiceIds[(int) $row->invoice_id] = true;
        }

        foreach (array_keys($invoiceIds) as $invoiceId) {
            $invoice = Invoice::query()->find($invoiceId);
            if ($invoice === null) {
                continue;
            }

            if (abs((float) $invoice->price_per_cbm - $newPrice) > 0.009) {
                $invoice->forceFill(['price_per_cbm' => $newPrice])->saveQuietly();
            }

            $invoice->recalculate();
        }
    }

    public function getStatusBadgeAttribute(): string
    {
        return 'badge-status-' . $this->status;
    }

    public function getApprovalStatusBadgeAttribute(): string
    {
        return 'badge-status-' . $this->approval_status;
    }

    public function getContainerCodeAttribute(): ?string
    {
        return $this->containers->first()?->partai;
    }

    /** @param array<string, mixed> $data */
    public function insertBarang(array $data, Request $request): self
    {
        return DB::transaction(function () use ($data, $request): self {
            $now = now();
            $row = self::filterBarangRow($data);

            if (
                (! array_key_exists('company_loyalty_amount', $row) || $row['company_loyalty_amount'] === null || $row['company_loyalty_amount'] === '')
            ) {
                $row['company_loyalty_amount'] = CompanyRevenueSettings::profitPerShipmentForCbmType($row['cbm_type'] ?? null);
            }

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
        \App\Support\SalesIncentiveService::syncForBarang($this);
        $this->recordInvoiceOnMasuk();
    }

    /** @param array<string, mixed> $old */
    /** @param array<string, mixed> $new */
    public function afterUpdate(array $old, array $new): void
    {
        ActivityLog::record('Barang updated', $this, $old, $new);

        \App\Support\SalesIncentiveService::syncForBarang($this);
    }

    public function recordInvoiceOnMasuk(): ?Invoice
    {
        if (! self::isAutoInvoiceEligibleStatus((string) $this->status)) {
            return null;
        }

        $invoice = $this->createInvoiceIfNeeded();
        if ($invoice !== null) {
            ActivityLog::record('Invoice generated', $invoice);

            return $invoice;
        }

        return null;
    }

    /** Lepas barang dari invoice draft saat ditarik kembali; invoice kosong ikut dihapus. */
    public function releaseDraftInvoicesOnWithdraw(): void
    {
        $invoiceIds = DB::table('invoice_items')
            ->where('barang_id', $this->id)
            ->distinct()
            ->pluck('invoice_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($invoiceIds === []) {
            return;
        }

        $draftInvoiceIds = DB::table('invoices')
            ->whereIn('id', $invoiceIds)
            ->where('status', 'draft')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($draftInvoiceIds === []) {
            return;
        }

        $removed = DB::table('invoice_items')
            ->where('barang_id', $this->id)
            ->whereIn('invoice_id', $draftInvoiceIds)
            ->delete();

        if ($removed < 1) {
            return;
        }

        self::reconcileInvoicesAfterItemRemoval($draftInvoiceIds);

        ActivityLog::record('Barang withdrawn — draft invoice line removed', $this, null, [
            'invoice_ids' => $draftInvoiceIds,
        ]);
    }

    public function recordInvoiceIfBongkar(?string $previousStatus = null): ?Invoice
    {
        if (! self::isBongkarStatus((string) $this->status)) {
            return null;
        }

        $justTransitioned = $previousStatus !== null
            && self::transitionedToBongkar($previousStatus, (string) $this->status);

        $invoice = $this->createInvoiceIfNeeded();
        if ($invoice !== null) {
            ActivityLog::record('Invoice generated', $invoice);
            if ($justTransitioned) {
                $this->syncInvoiceDueDateOnBongkar();
            }

            return $invoice->fresh();
        }

        if ($justTransitioned) {
            $this->syncInvoiceDueDateOnBongkar();
        } elseif ($previousStatus !== null) {
            return null;
        }

        $this->logInvoiceSkippedOnBongkar();

        return null;
    }

    /**
     * Barangs without an invoice line (masuk barang / belum bongkar, optional in-container filter).
     */
    public function scopePendingMasukInvoiceBackfill(Builder $query, bool $inContainerOnly = false): Builder
    {
        $query
            ->whereIn('status', self::AUTO_INVOICE_ELIGIBLE_STATUSES)
            ->whereDoesntHave('invoiceItems');

        if ($inContainerOnly) {
            $query->whereHas('containers');
        }

        return $query;
    }

    /**
     * Bongkar barangs without an invoice line (optionally only those loaded in a container).
     */
    public function scopePendingBongkarInvoiceBackfill(Builder $query, bool $inContainerOnly = false): Builder
    {
        $query
            ->where('status', self::STATUS_BONGKAR)
            ->whereDoesntHave('invoiceItems');

        if ($inContainerOnly) {
            $query->whereHas('containers');
        }

        return $query;
    }

    /**
     * @return 'no_customer'|'no_price'|'already_invoiced'|null
     */
    public function invoiceSkipReasonOnMasuk(): ?string
    {
        if (! self::isAutoInvoiceEligibleStatus((string) $this->status)) {
            return null;
        }

        if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
            return 'already_invoiced';
        }

        $this->ensureInvoiceBillableAttributes();

        return $this->invoiceSkipReasonAfterBillableAttributes();
    }

    /**
     * Read-only eligibility check (uses marking pricing, does not persist fixes).
     *
     * @return 'no_customer'|'no_price'|'already_invoiced'|null
     */
    public function projectedInvoiceSkipReasonOnMasuk(): ?string
    {
        if (! self::isAutoInvoiceEligibleStatus((string) $this->status)) {
            return null;
        }

        if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
            return 'already_invoiced';
        }

        $userId = $this->user_id;
        if (! $userId) {
            $markingValue = trim((string) ($this->marking ?? ''));
            if ($markingValue !== '') {
                $resolvedUserId = Marking::query()
                    ->whereBarangMarkingValue($markingValue)
                    ->value('user_id');
                if ($resolvedUserId !== null && (int) $resolvedUserId > 0) {
                    $userId = (int) $resolvedUserId;
                }
            }
        }

        if (! $userId) {
            return 'no_customer';
        }

        $pricePerCbm = (float) ($this->price_per_cbm ?? 0);
        if ($pricePerCbm <= 0) {
            $pricingRow = MarkingPricingResolver::forBarang($this);
            $pricePerCbm = $pricingRow !== null
                ? (float) ($pricingRow->volume_price ?? 0)
                : 0.0;
        }

        if ($pricePerCbm <= 0) {
            return 'no_price';
        }

        return null;
    }

    /**
     * @return 'no_customer'|'no_price'|'already_invoiced'|null
     */
    public function invoiceSkipReasonOnBongkar(): ?string
    {
        if (! self::isBongkarStatus((string) $this->status)) {
            return null;
        }

        if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
            return 'already_invoiced';
        }

        $this->ensureInvoiceBillableAttributes();

        return $this->invoiceSkipReasonAfterBillableAttributes();
    }

    /**
     * Read-only eligibility check (uses marking pricing, does not persist fixes).
     *
     * @return 'no_customer'|'no_price'|'already_invoiced'|null
     */
    public function projectedInvoiceSkipReasonOnBongkar(): ?string
    {
        if (! self::isBongkarStatus((string) $this->status)) {
            return null;
        }

        if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
            return 'already_invoiced';
        }

        $userId = $this->user_id;
        if (! $userId) {
            $markingValue = trim((string) ($this->marking ?? ''));
            if ($markingValue !== '') {
                $resolvedUserId = Marking::query()
                    ->whereBarangMarkingValue($markingValue)
                    ->value('user_id');
                if ($resolvedUserId !== null && (int) $resolvedUserId > 0) {
                    $userId = (int) $resolvedUserId;
                }
            }
        }

        if (! $userId) {
            return 'no_customer';
        }

        $pricePerCbm = (float) ($this->price_per_cbm ?? 0);
        if ($pricePerCbm <= 0) {
            $pricingRow = MarkingPricingResolver::forBarang($this);
            $pricePerCbm = $pricingRow !== null
                ? (float) ($pricingRow->volume_price ?? 0)
                : 0.0;
        }

        if ($pricePerCbm <= 0) {
            return 'no_price';
        }

        return null;
    }

    /**
     * @return 'no_customer'|'no_price'|null
     */
    private function invoiceSkipReasonAfterBillableAttributes(): ?string
    {
        if (! $this->user_id) {
            return 'no_customer';
        }

        if ((float) ($this->price_per_cbm ?? 0) <= 0) {
            return 'no_price';
        }

        return null;
    }

    private function logInvoiceSkippedOnBongkar(): void
    {
        $reason = $this->invoiceSkipReasonOnBongkar();
        if ($reason === null || $reason === 'already_invoiced') {
            return;
        }

        Log::warning('Bongkar invoice skipped', [
            'barang_id' => $this->id,
            'no_index' => $this->no_index,
            'marking' => $this->marking,
            'user_id' => $this->user_id,
            'price_per_cbm' => $this->price_per_cbm,
            'reason' => $reason,
        ]);
    }

    /**
     * Create draft invoices for billable barangs without invoice (masuk barang onward).
     *
     * @return int Number of invoices created (or attached via consolidation).
     */
    public static function backfillMissingInvoicesForMasuk(Builder $scope, int $limit = 100): int
    {
        self::provisionMissingCustomersForScope(clone $scope);

        $created = 0;

        $scope
            ->whereIn('status', self::AUTO_INVOICE_ELIGIBLE_STATUSES)
            ->whereDoesntHave('invoiceItems')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (self $barang) use (&$created): void {
                if ($barang->recordInvoiceOnMasuk() !== null) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Create draft invoices for bongkar barangs that are billable but have no line yet.
     *
     * @return int Number of invoices created (or attached via consolidation).
     */
    public static function backfillMissingInvoicesForBongkar(Builder $scope, int $limit = 100): int
    {
        self::provisionMissingCustomersForScope(clone $scope);

        $created = 0;

        $scope
            ->where('status', self::STATUS_BONGKAR)
            ->whereDoesntHave('invoiceItems')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (self $barang) use (&$created): void {
                if ($barang->recordInvoiceIfBongkar() !== null) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Auto-buat customer portal untuk marking yang barangnya belum punya user_id (blokir invoice).
     */
    public static function provisionMissingCustomersForScope(Builder $scope): int
    {
        $codes = (clone $scope)
            ->where(static function (Builder $q): void {
                $q->whereNull('user_id')->orWhere('user_id', 0);
            })
            ->whereNotNull('marking')
            ->where('marking', '!=', '')
            ->distinct()
            ->pluck('marking')
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->values();

        $provisioned = 0;
        foreach ($codes as $code) {
            $marking = Marking::query()->whereBarangMarkingValue($code)->first();
            if ($marking === null) {
                continue;
            }

            if ($marking->user_id === null || (int) $marking->user_id <= 0) {
                $marking->ensureCustomerUser();
                $marking->refresh();
            }

            if ($marking->user_id !== null && (int) $marking->user_id > 0) {
                $marking->syncBarangCustomerUserIds();
                $provisioned++;
            }
        }

        return $provisioned;
    }

    /** @param list<int|string> $invoiceIds */
    /** @param array<string, mixed> $snapshot */
    public static function afterDelete(array $invoiceIds, array $snapshot): void
    {
        ActivityLog::record('Barang deleted', null, $snapshot);

        self::reconcileInvoicesAfterItemRemoval($invoiceIds);
    }

    /** @param list<int|string> $invoiceIds */
    public static function reconcileInvoicesAfterItemRemoval(array $invoiceIds): void
    {
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
        $this->ensureInvoiceBillableAttributes();

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

        $this->loadMissing('containers');

        $lockKey = sprintf(
            'invoice-consolidation:%d:%s',
            (int) $this->user_id,
            \App\Support\InvoiceConsolidation::scopeKey($this),
        );

        return Cache::lock($lockKey, 15)->block(10, function () use ($pricePerCbm): ?Invoice {
            if (DB::table('invoice_items')->where('barang_id', $this->id)->exists()) {
                return null;
            }

            $existing = \App\Support\InvoiceConsolidation::findOpenInvoiceForBarang($this);
            if ($existing !== null && \App\Support\InvoiceConsolidation::isInvoiceOpenForMoreItems($existing)) {
                $invoice = \App\Support\InvoiceConsolidation::attachBarangToInvoice($existing, $this);
                $this->consolidateDraftInvoicesInLoadedContainers();
                \App\Support\InvoiceConsolidation::consolidateOpenDraftsForBarangScope($this);

                return $invoice;
            }

            $insuranceAmount = (float) $this->insurance_value * ((float) $this->insurance_rate / 100);
            // Jatuh tempo belum jalan sebelum bongkar (tampil "—" / null).
            // Setelah bongkar diisi lewat syncInvoiceDueDateOnBongkar / resolve di bawah.
            $dueDateValue = null;
            if (self::isBongkarStatus((string) $this->status)) {
                $dueDateValue = $this->resolveInvoiceDueDate()->format('Y-m-d');
            }
            $now = now();

            $invoiceId = (int) DB::table('invoices')->insertGetId([
                'invoice_no' => Invoice::generateNumber([$this]),
                'user_id' => $this->user_id,
                'price_per_cbm' => $pricePerCbm,
                'status' => 'draft',
                'due_date' => $dueDateValue,
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
            $this->consolidateDraftInvoicesInLoadedContainers();
            \App\Support\InvoiceConsolidation::consolidateOpenDraftsForBarangScope($this);

            return $invoice;
        });
    }

    /**
     * Fill missing customer / CBM price from marking before auto-invoice.
     * Jika marking belum punya customer portal, buat/link dulu lalu isi barangs.user_id.
     */
    private function ensureInvoiceBillableAttributes(): void
    {
        if (! $this->user_id) {
            $markingValue = trim((string) ($this->marking ?? ''));
            if ($markingValue !== '') {
                $marking = Marking::query()
                    ->whereBarangMarkingValue($markingValue)
                    ->first();

                if ($marking !== null) {
                    if ($marking->user_id === null || (int) $marking->user_id <= 0) {
                        $marking->ensureCustomerUser();
                        $marking->refresh();
                    }

                    if ($marking->user_id !== null && (int) $marking->user_id > 0) {
                        $this->forceFill(['user_id' => (int) $marking->user_id])->saveQuietly();
                        $marking->syncBarangCustomerUserIds();
                    }
                }
            }
        }

        $pricingRow = MarkingPricingResolver::forBarang($this);
        if ($pricingRow === null) {
            return;
        }

        $updates = [];
        $volumePrice = (float) ($pricingRow->volume_price ?? 0);
        if ((float) ($this->price_per_cbm ?? 0) <= 0 && $volumePrice > 0) {
            $updates['price_per_cbm'] = $volumePrice;
        }

        $gwPrice = (float) ($pricingRow->gw_price ?? 0);
        if ((float) ($this->weight_price ?? 0) <= 0 && $gwPrice > 0) {
            $updates['weight_price'] = $gwPrice;
        }

        $marking = Marking::query()->whereBarangMarkingValue(trim((string) ($this->marking ?? '')))->first();
        if ($marking !== null && (float) ($this->weight_threshold_kg ?? 0) <= 0) {
            $fromMarking = max(0, (float) ($marking->max_weight_kg ?? 0));
            if ($fromMarking > 0) {
                $updates['weight_threshold_kg'] = $fromMarking;
            }
        }

        if ($updates !== []) {
            $this->forceFill($updates)->saveQuietly();
        }
    }

    private function consolidateDraftInvoicesInLoadedContainers(): void
    {
        $this->loadMissing('containers');

        foreach ($this->containers as $container) {
            \App\Support\InvoiceConsolidation::consolidateDraftsInContainer(
                $container->fresh(['barangs.containers']),
                $this,
            );
        }
    }

    public function syncStuffingTracking(?int $createdBy = null): ?TrackingStatus
    {
        // Jangan buat stuffing baru kalau barang sudah lewat tahap gudang / sudah punya update lanjut.
        if (Container::barangShipmentProgressRank((string) $this->status) > 1) {
            return null;
        }

        if (DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->whereIn('status', [self::STATUS_ETD, self::STATUS_ETA, self::STATUS_BONGKAR])
            ->exists()) {
            return null;
        }

        $autoDescriptions = \App\Support\CustomerTrackingPresenter::autoStuffingDescriptions();
        if ($autoDescriptions !== [] && DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->whereIn('description', $autoDescriptions)
            ->exists()) {
            return null;
        }

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

        $now = now();
        $eventDate = TrackingEventDate::resolve($this->tanggal_masuk ?? $now);
        $insert = [
            'barang_id' => $this->id,
            'status' => self::STATUS_STUFFING,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (TrackingEventDate::barangColumnReady()) {
            $insert['event_date'] = $eventDate;
        }

        $id = (int) DB::table('tracking_statuses')->insertGetId($insert);

        return TrackingStatus::query()->find($id);
    }

    /**
     * Hapus auto-stuffing dobel / yang muncul setelah ETD/ETA/bongkar (timeline customer).
     *
     * @return int Jumlah baris yang dihapus
     */
    public function pruneRedundantStuffingTracking(): int
    {
        $deleteIds = $this->redundantStuffingTrackingIds();
        if ($deleteIds === []) {
            return 0;
        }

        return (int) DB::table('tracking_statuses')
            ->where('barang_id', $this->id)
            ->whereIn('id', $deleteIds)
            ->delete();
    }

    /**
     * @return list<int>
     */
    public function redundantStuffingTrackingIds(): array
    {
        $autoDescriptions = \App\Support\CustomerTrackingPresenter::autoStuffingDescriptions();

        $rows = $this->trackingStatuses()
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'status', 'description', 'created_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'description' => trim((string) ($row->description ?? '')),
                'created_at' => $row->created_at,
            ])
            ->all();

        return self::resolveRedundantStuffingIds($rows, $autoDescriptions);
    }

    /**
     * @param  list<array{id: int, status: string, description: string, created_at: mixed}>  $rows  Oldest → newest
     * @param  list<string>  $autoDescriptions
     * @return list<int>
     */
    public static function resolveRedundantStuffingIds(array $rows, array $autoDescriptions): array
    {
        $firstAdvancedAt = null;
        foreach ($rows as $row) {
            if (in_array((string) ($row['status'] ?? ''), [self::STATUS_ETD, self::STATUS_ETA, self::STATUS_BONGKAR], true)) {
                $firstAdvancedAt = $row['created_at'] ?? null;
                break;
            }
        }

        $advancedTs = null;
        if ($firstAdvancedAt !== null && $firstAdvancedAt !== '') {
            $advancedTs = $firstAdvancedAt instanceof \DateTimeInterface
                ? Carbon::instance(\DateTimeImmutable::createFromInterface($firstAdvancedAt))->getTimestamp()
                : Carbon::parse((string) $firstAdvancedAt)->getTimestamp();
        }

        $keepAutoId = null;
        $deleteIds = [];

        foreach ($rows as $row) {
            $isAutoStuffing = (string) ($row['status'] ?? '') === self::STATUS_STUFFING
                && in_array((string) ($row['description'] ?? ''), $autoDescriptions, true);

            if (! $isAutoStuffing) {
                continue;
            }

            $created = $row['created_at'] ?? null;
            $createdTs = null;
            if ($created instanceof \DateTimeInterface) {
                $createdTs = Carbon::instance(\DateTimeImmutable::createFromInterface($created))->getTimestamp();
            } elseif ($created !== null && $created !== '') {
                $createdTs = Carbon::parse((string) $created)->getTimestamp();
            }

            if ($advancedTs !== null && $createdTs !== null && $createdTs > $advancedTs) {
                $deleteIds[] = (int) $row['id'];
                continue;
            }

            if ($keepAutoId === null) {
                $keepAutoId = (int) $row['id'];
                continue;
            }

            $deleteIds[] = (int) $row['id'];
        }

        // Kalau sudah ada update lanjut (ETD+), auto stuffing tidak perlu di riwayat customer.
        if ($advancedTs !== null && $keepAutoId !== null) {
            $deleteIds[] = $keepAutoId;
        }

        return array_values(array_unique($deleteIds));
    }

    public function syncEtdTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncShipmentTrackingStatus(
            self::STATUS_ETD,
            $location !== null && trim($location) !== '' ? $location : (trim((string) ($this->destination ?? '')) ?: __('tracking.auto_etd_location_fallback')),
            $description !== null && trim($description) !== '' ? $description : __('tracking.auto_etd_description'),
            $createdBy,
        );
    }

    public function syncEtaTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncShipmentTrackingStatus(
            self::STATUS_ETA,
            $location !== null && trim($location) !== '' ? $location : (trim((string) ($this->destination ?? '')) ?: __('tracking.auto_eta_location_fallback')),
            $description !== null && trim($description) !== '' ? $description : __('tracking.auto_eta_description'),
            $createdBy,
        );
    }

    public function syncBongkarTracking(?string $location = null, ?string $description = null, ?int $createdBy = null): ?TrackingStatus
    {
        return $this->syncShipmentTrackingStatus(
            self::STATUS_BONGKAR,
            $location !== null && trim($location) !== '' ? $location : (trim((string) ($this->destination ?? '')) ?: __('tracking.auto_bongkar_location_fallback')),
            $description !== null && trim($description) !== '' ? $description : __('tracking.auto_bongkar_description'),
            $createdBy,
        );
    }

    public function syncShipmentTrackingStatus(
        string $status,
        ?string $location,
        ?string $description,
        ?int $createdBy = null,
        ?string $eventDate = null,
    ): ?TrackingStatus {
        $location = $location !== null ? trim($location) : null;
        $location = $location !== '' ? $location : null;
        $description = $description !== null ? trim($description) : null;
        $description = $description !== '' ? $description : null;
        $eventDate = TrackingEventDate::resolve($eventDate);

        $latestColumns = ['id', 'status', 'location', 'description', 'created_at'];
        if (TrackingEventDate::barangColumnReady()) {
            $latestColumns[] = 'event_date';
        }
        $latest = $this->trackingStatuses()
            ->select($latestColumns)
            ->first();

        if (
            $latest !== null
            && $latest->status === $status
            && trim((string) ($latest->location ?? '')) === ($location ?? '')
            && trim((string) ($latest->description ?? '')) === ($description ?? '')
        ) {
            $latestEvent = TrackingEventDate::normalize($latest->event_date ?? null)
                ?? TrackingEventDate::normalize($latest->created_at);
            if (
                $latestEvent !== $eventDate
                && TrackingEventDate::barangColumnReady()
            ) {
                $latest->forceFill(['event_date' => $eventDate])->save();
            }

            return $latest->fresh();
        }

        $now = now();
        $insert = [
            'barang_id' => $this->id,
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (TrackingEventDate::barangColumnReady()) {
            $insert['event_date'] = $eventDate;
        }

        $id = (int) DB::table('tracking_statuses')->insertGetId($insert);

        return TrackingStatus::query()->find($id);
    }

    public function syncContainerLoadedTracking(Container $container, ?int $createdBy = null): ?TrackingStatus
    {
        $location = null;
        if ($container->gudang_china_id) {
            $location = DB::table('master_gudang_china')
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
        $insert = [
            'barang_id' => $this->id,
            'status' => self::STATUS_STUFFING,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (TrackingEventDate::barangColumnReady()) {
            $insert['event_date'] = TrackingEventDate::resolve($now);
        }

        $id = (int) DB::table('tracking_statuses')->insertGetId($insert);

        return TrackingStatus::query()->find($id);
    }

    private function resolveInvoiceDueDate(?Carbon $from = null): Carbon
    {
        return InvoiceDueResolver::resolveForBarang($this, null, $from)['due_date'];
    }

    /**
     * Jatuh tempo dihitung dari hari status jadi bongkar (+ tempo marking).
     * Hari jatuh tempo = telat 0 hari; besoknya = telat 1 hari.
     */
    public function syncInvoiceDueDateOnBongkar(?Carbon $bongkarDate = null): void
    {
        $dueDate = $this->resolveInvoiceDueDate($bongkarDate ?? today());
        $invoiceIds = DB::table('invoice_items')
            ->where('barang_id', $this->id)
            ->distinct()
            ->pluck('invoice_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($invoiceIds === []) {
            return;
        }

        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->whereIn('status', ['draft', 'sent'])
            ->with('payments')
            ->get();

        foreach ($invoices as $invoice) {
            $paid = (float) $invoice->payments
                ->where('status', 'approved')
                ->sum('amount');
            $remaining = max(0, (float) $invoice->grand_total - $paid);
            if (InvoiceStatusPresenter::isFullyPaid($paid, $remaining)) {
                continue;
            }

            $invoice->forceFill([
                'due_date' => $dueDate->format('Y-m-d'),
            ])->save();
        }
    }

    private function persistRelations(Request $request): void
    {
        $this->syncProducts($request);
        $this->syncAdditionalPrices($request);
        $this->recalculateTotals();
    }

    public function syncProductsFromRequest(Request $request): void
    {
        $this->syncProducts($request);
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
            if (! empty($row['id'])) {
                return false;
            }

            if (trim((string) ($row['product_name'] ?? '')) !== '') {
                return false;
            }

            foreach (['pcs_per_box', 'price_per_unit_rmb'] as $field) {
                if ((float) ($row[$field] ?? 0) > 0) {
                    return false;
                }
            }

            return true;
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
    private static function productPayloadFromInputRow(
        array $row,
        ?array $parentRow,
        bool $isSub,
        bool $parentHasSubs = false,
    ): array {
        if ($isSub) {
            $parentRow ??= [];

            return [
                'product_name' => trim((string) ($row['product_name'] ?? '')) ?: null,
                'material' => null,
                'pcs_per_box' => (int) ($row['pcs_per_box'] ?? 0),
                'ctns' => 0,
                'gross_weight_kg' => 0,
                'length_cm' => 0,
                'width_cm' => 0,
                'height_cm' => 0,
                'price_per_unit_rmb' => $row['price_per_unit_rmb'] ?? 0,
                'total_price_rmb' => 0,
                '_parent_ctns' => max(0, (int) ($parentRow['ctns'] ?? 0)),
            ];
        }

        if ($parentHasSubs) {
            return [
                'product_name' => null,
                'material' => $row['material'] ?? null,
                'pcs_per_box' => 0,
                'ctns' => (int) ($row['ctns'] ?? 0),
                'gross_weight_kg' => $row['gross_weight_kg'] ?? 0,
                'length_cm' => $row['length_cm'] ?? 0,
                'width_cm' => $row['width_cm'] ?? 0,
                'height_cm' => $row['height_cm'] ?? 0,
                'price_per_unit_rmb' => 0,
                'total_price_rmb' => 0,
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
            if (self::productRowHasFileUploads($request, $index)) {
                $hasUploads = true;
            }
        }

        if (! $hasExistingIds && ! $hasUploads) {
            if ($this->bulkInsertProducts($request, $rows)) {
                $this->normalizeProductSortOrder();

                return;
            }
        }

        $this->syncProductsIteratively($request, $rows, $hasExistingIds);
        $this->normalizeProductSortOrder();
    }

    private function normalizeProductSortOrder(): void
    {
        $rows = DB::table('barang_products')
            ->where('barang_id', $this->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $parents = $rows->whereNull('parent_id')->values();
        $subsByParent = $rows->whereNotNull('parent_id')->groupBy('parent_id');
        $orderedIds = [];

        foreach ($parents as $parent) {
            $orderedIds[] = (int) $parent->id;

            $subs = $subsByParent->get($parent->id) ?? collect();
            foreach ($subs->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]) as $sub) {
                $orderedIds[] = (int) $sub->id;
            }
        }

        $placed = array_flip($orderedIds);
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! isset($placed[$id])) {
                $orderedIds[] = $id;
            }
        }

        $now = now();
        foreach ($orderedIds as $sortOrder => $id) {
            DB::table('barang_products')
                ->where('id', $id)
                ->where('barang_id', $this->id)
                ->update([
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                ]);
        }
    }

    /** @param array<int, mixed> $rows */
    /** @return array<int, bool> */
    public static function parentRowIndexesWithSubs(array $rows): array
    {
        $indexes = [];
        $lastParentIndex = null;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (self::isSubProductInputRow($row)) {
                if ($lastParentIndex !== null) {
                    $indexes[$lastParentIndex] = true;
                }

                continue;
            }

            $lastParentIndex = $index;
        }

        return $indexes;
    }

    /** @param array<int, mixed> $rows */
    private function bulkInsertProducts(Request $request, array $rows): bool
    {
        $now = now();
        /** @var list<array<string, mixed>> $pending */
        $pending = [];
        $sort = 0;
        $lastParentInput = null;
        $lastParentQueueIndex = null;
        $parentRowsWithSubs = self::parentRowIndexesWithSubs($rows);

        foreach ($rows as $index => $row) {
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
                self::productPayloadFromInputRow(
                    $row,
                    $lastParentInput,
                    $isSub,
                    ! $isSub && ($parentRowsWithSubs[$index] ?? false),
                ),
                [
                    'barang_id' => $this->id,
                    'parent_id' => null,
                    'sort_order' => $sort++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ));

            $payload = self::mergeProductPhotoUploads($request, $index, $row, null, $payload);

            if (! $isSub && ($parentRowsWithSubs[$index] ?? false)) {
                $payload['photo_path'] = null;
            }

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
    /** @param array<int, mixed> $rows */
    private function syncProductsIteratively(Request $request, array $rows, bool $hasExistingIds): void
    {
        $existing = $hasExistingIds
            ? DB::table('barang_products')->where('barang_id', $this->id)->get()->keyBy('id')
            : collect();

        $this->assertProductSyncNotTruncated($request, $rows, $existing);

        $keepIds = [];
        $sort = 0;
        $now = now();
        $lastParentInput = null;
        $lastParentId = null;
        $parentRowsWithSubs = self::parentRowIndexesWithSubs($rows);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (self::isEmptyProductInputRow($row) && ! self::productRowHasFileUploads($request, $index)) {
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
                self::productPayloadFromInputRow(
                    $row,
                    $lastParentInput,
                    $isSub,
                    ! $isSub && ($parentRowsWithSubs[$index] ?? false),
                ),
                [
                    'sort_order' => $sort++,
                    'parent_id' => $isSub ? $lastParentId : null,
                ],
            ));

            $payload = self::mergeProductPhotoUploads($request, $index, $row, $product, $payload);

            if (! $isSub && ($parentRowsWithSubs[$index] ?? false)) {
                if ($product?->photo_path) {
                    Storage::disk('public')->delete($product->photo_path);
                }
                $payload['photo_path'] = null;
            }

            if ($product) {
                $hasNewPhoto = self::payloadHasProductPhotoChanges($payload);
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

    /**
     * @param  array<int, mixed>  $rows
     * @param  \Illuminate\Support\Collection<int, object>  $existing
     */
    private function assertProductSyncNotTruncated(Request $request, array $rows, Collection $existing): void
    {
        if ($existing->isEmpty() || $request->filled('products_payload')) {
            return;
        }

        $incomingIds = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $incomingIds[(int) $row['id']] = true;
        }

        if (count($incomingIds) >= $existing->count()) {
            return;
        }

        throw ValidationException::withMessages([
            'products' => [__('barang.products_payload_required')],
        ]);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private static function applyProductCalculations(array $payload): array
    {
        $parentCtns = max(0, (int) ($payload['_parent_ctns'] ?? 0));
        unset($payload['_parent_ctns']);

        $ctns = max(0, (int) ($payload['ctns'] ?? 0));
        $ctnsForRmb = $ctns > 0 ? $ctns : $parentCtns;
        $length = (float) ($payload['length_cm'] ?? 0);
        $width = (float) ($payload['width_cm'] ?? 0);
        $height = (float) ($payload['height_cm'] ?? 0);
        $payload['volume_cbm'] = \App\Support\CbmFormat::truncate(
            \App\Support\CbmVolume::rawLine($length, $width, $height, $ctns),
        );

        $pcs = max(0, (int) ($payload['pcs_per_box'] ?? 0));
        $unitPrice = \App\Support\RmbFormat::round((float) ($payload['price_per_unit_rmb'] ?? 0));
        $payload['price_per_unit_rmb'] = $unitPrice;
        if ($unitPrice > 0 && $pcs > 0) {
            $payload['total_price_rmb'] = \App\Support\RmbFormat::lineTotal($unitPrice, $pcs, max(1, $ctnsForRmb));
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
    public static function productRowHasFileUploads(Request $request, int $index): bool
    {
        foreach (self::productUploadFileKeys() as $fileKey) {
            if ($request->hasFile("products.{$index}.{$fileKey}")) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function productUploadFileKeys(): array
    {
        return ['photo', 'length_photo', 'width_photo', 'height_photo'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeProductPhotoUploads(Request $request, int $index, array $row, ?object $product, array $payload): array
    {
        $file = $request->file("products.{$index}.photo");
        if ($file) {
            if ($product?->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $payload['photo_path'] = $file->store('barang-products', 'public');
        } elseif (
            $request->boolean("products.{$index}.remove_photo")
            || (array_key_exists('photo_path', $row) && ($row['photo_path'] === null || $row['photo_path'] === ''))
        ) {
            if ($product?->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $payload['photo_path'] = null;
        } elseif (! array_key_exists('photo_path', $payload) && ! empty($row['photo_path']) && is_string($row['photo_path'])) {
            $resolved = self::resolvePersistedProductPhotoPath($row['photo_path'], 'barang-products');
            if ($resolved !== null) {
                $payload['photo_path'] = $resolved;
            }
        }

        foreach (['length', 'width', 'height'] as $dimension) {
            $fileKey = "{$dimension}_photo";
            $pathKey = "{$dimension}_photo_path";
            $removeKey = "remove_{$dimension}_photo";
            $uploaded = $request->file("products.{$index}.{$fileKey}");

            if ($uploaded) {
                if ($product?->{$pathKey}) {
                    Storage::disk('public')->delete($product->{$pathKey});
                }
                $payload[$pathKey] = $uploaded->store('barang-products/dimensions', 'public');
            } elseif (
                $request->boolean("products.{$index}.{$removeKey}")
                || (array_key_exists($pathKey, $row) && ($row[$pathKey] === null || $row[$pathKey] === ''))
            ) {
                if ($product?->{$pathKey}) {
                    Storage::disk('public')->delete($product->{$pathKey});
                }
                $payload[$pathKey] = null;
            } elseif (! array_key_exists($pathKey, $payload) && ! empty($row[$pathKey]) && is_string($row[$pathKey])) {
                $resolved = self::resolvePersistedProductPhotoPath($row[$pathKey], 'barang-products/dimensions');
                if ($resolved !== null) {
                    $payload[$pathKey] = $resolved;
                }
            }
        }

        return $payload;
    }

    private static function resolvePersistedProductPhotoPath(string $rawPath, string $targetDir): ?string
    {
        $promoted = self::promoteStagedPublicFile($rawPath, $targetDir);
        if ($promoted !== null) {
            return $promoted;
        }

        $normalized = \App\Support\PublicStorageUrl::normalizePath($rawPath);
        if ($normalized === null) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            return null;
        }

        if (self::isStagedPublicFilePath($normalized)) {
            $filename = basename($normalized);
            $dest = rtrim($targetDir, '/') . '/' . uniqid('p_', true) . '_' . $filename;
            $disk->copy($normalized, $dest);

            return $dest;
        }

        return $normalized;
    }

    private static function promoteStagedPublicFile(string $path, string $targetDir): ?string
    {
        $normalized = \App\Support\PublicStorageUrl::normalizePath($path);
        if ($normalized === null || ! self::isStagedPublicFilePath($normalized)) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            return null;
        }

        $filename = basename($normalized);
        $newPath = rtrim($targetDir, '/') . '/' . $filename;
        if ($disk->exists($newPath)) {
            $newPath = rtrim($targetDir, '/') . '/' . uniqid('p_', true) . '_' . $filename;
        }

        $disk->copy($normalized, $newPath);

        return $newPath;
    }

    private static function isStagedPublicFilePath(string $normalized): bool
    {
        return str_contains($normalized, 'barang-edit-requests/')
            || str_contains($normalized, 'barang-product-staging/');
    }

    /** @param  array<string, mixed>  $payload */
    private static function payloadHasProductPhotoChanges(array $payload): bool
    {
        foreach (['photo_path', 'length_photo_path', 'width_photo_path', 'height_photo_path'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function productChanged(object $product, array $payload): bool
    {
        if (self::payloadHasProductPhotoChanges($payload)) {
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
