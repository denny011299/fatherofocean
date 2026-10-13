<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\TrackingEventDate;

final class Container extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_LOADING = 'loading';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_UNLOADING = 'unloading';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const CURRENT_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_LOADING,
        self::STATUS_SHIPPED,
        self::STATUS_ARRIVED,
        self::STATUS_UNLOADING,
        self::STATUS_CLOSED,
    ];

    public const TRACKING_LOADING = 'loading';

    public const TRACKING_ETD = 'etd';

    public const TRACKING_ETA = 'eta';

    public const TRACKING_BEA_CUKAI = 'bea_cukai';

    public const TRACKING_BONGKAR = 'bongkar';

    public const TRACKING_DITARIK_KEMBALI = 'ditarik_kembali';

    /** @var list<string> */
    public const TRACKING_STATUSES = [
        self::TRACKING_LOADING,
        self::TRACKING_ETD,
        self::TRACKING_ETA,
        self::TRACKING_BEA_CUKAI,
        self::TRACKING_BONGKAR,
    ];

    /** @var list<string> Staff container tracking summary cards (includes withdrawn barang count). */
    public const TRACKING_SUMMARY_STATUSES = [
        self::TRACKING_DITARIK_KEMBALI,
        self::TRACKING_LOADING,
        self::TRACKING_ETD,
        self::TRACKING_ETA,
        self::TRACKING_BEA_CUKAI,
        self::TRACKING_BONGKAR,
    ];

    protected $fillable = [
        'partai',
        'container_no',
        'no_bl',
        'type',
        'branch',
        'gudang_china_id',
        'marking_id',
        'alamat_gudang_china',
        'max_length',
        'max_width',
        'max_height',
        'max_volume',
        'load_date',
        'etd',
        'eta',
        'insurance_value',
        'insurance_rate',
        'insurance_date',
        'gate_pass_date',
        'notes',
        'arrival_date_actual',
        'is_join',
        'route',
        'department_item',
        'length_type',
        'port_id',
        'departure_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'load_date' => 'date',
            'etd' => 'date',
            'eta' => 'date',
            'insurance_date' => 'date',
            'gate_pass_date' => 'date',
            'arrival_date_actual' => 'date',
            'max_length' => 'decimal:2',
            'max_width' => 'decimal:2',
            'max_height' => 'decimal:2',
            'max_volume' => 'decimal:2',
            'insurance_value' => 'decimal:2',
            'insurance_rate' => 'decimal:2',
            'is_join' => 'boolean',
        ];
    }

    public const TYPES = [
        '20ft' => ['length' => 589, 'width' => 234, 'height' => 238, 'volume' => 33.10],
        '40ft' => ['length' => 1203, 'width' => 234, 'height' => 238, 'volume' => 67.50],
        '40ft HC' => ['length' => 1203, 'width' => 234, 'height' => 269, 'volume' => 76.20],
        '45ft' => ['length' => 1350, 'width' => 234, 'height' => 269, 'volume' => 85.00],
    ];

    /** @deprecated Use partai */
    protected function code(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->partai);
    }

    /** @deprecated Use no_bl */
    protected function sealNo(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->no_bl);
    }

    protected function partai(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->attributes['partai'] ?? $this->attributes['code'] ?? null,
        );
    }

    protected function noBl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->attributes['no_bl'] ?? $this->attributes['seal_no'] ?? null,
        );
    }

    public function gudangChina(): BelongsTo
    {
        return $this->belongsTo(MasterGudangChina::class, 'gudang_china_id');
    }

    public function marking(): BelongsTo
    {
        return $this->belongsTo(Marking::class);
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function barangs(): BelongsToMany
    {
        return $this->belongsToMany(Barang::class, 'container_barangs')
            ->withPivot('loaded_at', 'loaded_by')
            ->withTimestamps();
    }

    public function bongkarMuatSessions(): HasMany
    {
        return $this->hasMany(BongkarMuatSession::class);
    }

    public function deliveryTrips(): HasMany
    {
        return $this->hasMany(DeliveryTrip::class);
    }

    public function latestDeliveryTrip(): HasOne
    {
        return $this->hasOne(DeliveryTrip::class)->latestOfMany();
    }

    public function trackingStatuses(): HasMany
    {
        $relation = $this->hasMany(ContainerTrackingStatus::class);
        if (TrackingEventDate::containerColumnReady()) {
            return $relation
                ->orderByRaw('COALESCE(event_date, DATE(created_at)) DESC')
                ->orderByDesc('id');
        }

        return $relation->orderByDesc('created_at');
    }

    public function latestTracking(): HasOne
    {
        if (TrackingEventDate::containerColumnReady()) {
            return $this->hasOne(ContainerTrackingStatus::class)->ofMany([
                'event_date' => 'max',
                'id' => 'max',
            ]);
        }

        return $this->hasOne(ContainerTrackingStatus::class)->latestOfMany();
    }

    public function getUsedVolumeAttribute(): float
    {
        return (float) $this->barangs->sum(static function (Barang $barang): float {
            if ($barang->relationLoaded('products')
                && $barang->products->contains(static fn ($p): bool => $p->parent_id === null)) {
                return $barang->displayTotalCbm();
            }

            return (float) ($barang->total_cbm ?? 0);
        });
    }

    public function getTotalCtnsAttribute(): int
    {
        return (int) $this->barangs->sum('ctns');
    }

    public function getRemainingVolumeAttribute(): float
    {
        return max(0, (float) $this->max_volume - $this->used_volume);
    }

    public function getCapacityPercentAttribute(): float
    {
        if ($this->max_volume <= 0) {
            return 0;
        }

        return round(($this->used_volume / (float) $this->max_volume) * 100, 1);
    }

    public function getCapacityLevelAttribute(): string
    {
        $pct = $this->capacity_percent;
        if ($pct >= 95) {
            return 'danger';
        }
        if ($pct >= 75) {
            return 'warning';
        }

        return 'safe';
    }

    public function canFit(float $cbm): bool
    {
        return ($this->used_volume + $cbm) <= (float) $this->max_volume;
    }

    /**
     * Container has departed when ETD is set and strictly before today (app timezone).
     */
    public function hasDeparted(): bool
    {
        if ($this->etd === null) {
            return false;
        }

        $etd = $this->etd instanceof Carbon
            ? $this->etd
            : Carbon::parse($this->etd);

        return $etd->startOfDay()->lt(now()->startOfDay());
    }

    public function canBeDeleted(): bool
    {
        return ! $this->hasDeparted();
    }

    public function canBeEdited(): bool
    {
        return ! $this->hasDeparted();
    }

    public function getReadyInvoicingAttribute(): int
    {
        return $this->barangs->where('price_per_cbm', '>', 0)->count();
    }

    public function getContSealLabelAttribute(): string
    {
        $parts = array_filter([$this->container_no, $this->no_bl]);

        return implode(' / ', $parts) ?: '-';
    }

    public function getCodeCtnsLabelAttribute(): string
    {
        return ($this->partai ?? '-') . ' / ' . $this->total_ctns;
    }

    public function getBranchLabelAttribute(): string
    {
        if (! $this->branch) {
            return '-';
        }
        $master = MasterBranch::where('code', $this->branch)->first();

        return $master ? $master->name : $this->branch;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::trackingStatusBadge($this->displayTrackingStatus());
    }

    public function displayTrackingStatus(): ?string
    {
        if ($this->relationLoaded('latestTracking') && $this->latestTracking !== null) {
            return $this->latestTracking->status;
        }

        return self::trackingStatusFromCurrentState(
            (string) ($this->status ?? self::STATUS_OPEN),
            $this->relationLoaded('barangs') ? $this->barangs : null,
        );
    }

    public function resolveTrackingPayload(
        string $trackingStatus,
        ?string $location = null,
        ?string $description = null,
        ?string $currentStatus = null,
    ): array {
        $location = trim((string) $location);
        $description = trim((string) $description);

        if ($location === '') {
            $location = $this->defaultTrackingLocation($trackingStatus);
        }

        if ($description === '') {
            $description = $this->defaultTrackingDescription($trackingStatus);
        }

        return [
            'status' => $trackingStatus,
            'location' => $location !== '' ? $location : null,
            'description' => $description !== '' ? $description : null,
            'current_status' => $currentStatus ?? self::currentStatusForTrackingStatus($trackingStatus),
        ];
    }

    public function syncLoadingTracking(
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
    ): ?ContainerTrackingStatus {
        // Jangan regres status/timeline ke stuffing jika container sudah ETD/ETA/bea cukai/bongkar.
        $advanced = $this->trackingStatusBeyondLoading();
        if ($advanced !== null) {
            return $this->relationLoaded('latestTracking')
                ? $this->latestTracking
                : $this->trackingStatuses()->first();
        }

        // Satu kali stuffing cukup — jangan dobel tiap kali barang dimuat / locale beda.
        $existingLoading = $this->trackingStatuses()
            ->where('status', self::TRACKING_LOADING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($existingLoading !== null) {
            if (($this->status ?? null) !== self::STATUS_LOADING) {
                $this->forceFill(['status' => self::STATUS_LOADING])->save();
            }

            return $existingLoading;
        }

        return $this->syncTrackingStatus(self::TRACKING_LOADING, $location, $description, $createdBy, $currentStatus);
    }

    /**
     * Hapus stuffing/loading dobel: sisakan paling awal sebelum ETD/ETA/bongkar; buang yang muncul setelahnya.
     *
     * @return int Jumlah baris timeline yang dihapus
     */
    public function pruneRedundantLoadingTracking(): int
    {
        $deleteIds = $this->redundantLoadingTrackingIds();
        if ($deleteIds === []) {
            return 0;
        }

        return (int) DB::table('container_tracking_statuses')
            ->where('container_id', $this->id)
            ->whereIn('id', $deleteIds)
            ->delete();
    }

    /**
     * @return list<int>
     */
    public function redundantLoadingTrackingIds(): array
    {
        $loadings = $this->trackingStatuses()
            ->where('status', self::TRACKING_LOADING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'created_at' => $row->created_at,
            ])
            ->all();

        $firstAdvancedAt = $this->trackingStatuses()
            ->whereIn('status', [
                self::TRACKING_ETD,
                self::TRACKING_ETA,
                self::TRACKING_BEA_CUKAI,
                self::TRACKING_BONGKAR,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('created_at');

        return self::resolveRedundantLoadingIds($loadings, $firstAdvancedAt);
    }

    /**
     * @param  list<array{id: int, created_at: mixed}>  $loadings  Ordered oldest → newest
     * @return list<int>
     */
    public static function resolveRedundantLoadingIds(array $loadings, mixed $firstAdvancedAt): array
    {
        if ($loadings === []) {
            return [];
        }

        $advancedTs = null;
        if ($firstAdvancedAt !== null && $firstAdvancedAt !== '') {
            $advancedTs = $firstAdvancedAt instanceof \DateTimeInterface
                ? Carbon::instance(\DateTimeImmutable::createFromInterface($firstAdvancedAt))->getTimestamp()
                : Carbon::parse((string) $firstAdvancedAt)->getTimestamp();
        }

        $keepId = null;
        foreach ($loadings as $row) {
            $created = $row['created_at'] ?? null;
            $createdTs = null;
            if ($created instanceof \DateTimeInterface) {
                $createdTs = Carbon::instance(\DateTimeImmutable::createFromInterface($created))->getTimestamp();
            } elseif ($created !== null && $created !== '') {
                $createdTs = Carbon::parse((string) $created)->getTimestamp();
            }

            if ($advancedTs !== null && $createdTs !== null && $createdTs > $advancedTs) {
                continue;
            }

            $keepId = (int) $row['id'];

            break;
        }

        $deleteIds = [];
        foreach ($loadings as $row) {
            $id = (int) $row['id'];
            if ($keepId !== null && $id === $keepId) {
                continue;
            }
            $deleteIds[] = $id;
        }

        return $deleteIds;
    }

    /**
     * Latest container tracking past loading (etd/eta/bea_cukai/bongkar), or null if still open/loading.
     */
    public function trackingStatusBeyondLoading(): ?string
    {
        $status = null;

        if ($this->relationLoaded('latestTracking') && $this->latestTracking !== null) {
            $status = $this->latestTracking->status;
        } else {
            $status = $this->trackingStatuses()->value('status');
        }

        if ($status === null || $status === '') {
            $status = $this->displayTrackingStatus();
        }

        if (! is_string($status) || $status === '' || $status === self::TRACKING_LOADING) {
            return null;
        }

        return in_array($status, [
            self::TRACKING_ETD,
            self::TRACKING_ETA,
            self::TRACKING_BEA_CUKAI,
            self::TRACKING_BONGKAR,
        ], true) ? $status : null;
    }

    /**
     * Progress rank for comparing barang shipment status (higher = further along).
     */
    public static function barangShipmentProgressRank(?string $status): int
    {
        return match (Barang::normalizeShipmentStatus((string) $status)) {
            Barang::STATUS_BONGKAR => 4,
            Barang::STATUS_ETA => 3,
            Barang::STATUS_ETD => 2,
            Barang::STATUS_STUFFING, Barang::STATUS_INBOUND => 1,
            default => 0,
        };
    }

    /**
     * Align all loaded barangs to a container tracking status.
     *
     * @param  bool  $onlyUpgrade  When true, never downgrade a barang that is already further along.
     * @return int Number of barangs whose status was changed
     */
    public function cascadeTrackingToBarangs(
        string $trackingStatus,
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        bool $onlyUpgrade = false,
        ?string $eventDate = null,
    ): int {
        $barangStatus = self::barangStatusForTrackingStatus($trackingStatus);
        $expectedRank = self::barangShipmentProgressRank($barangStatus);
        $location = $location !== null ? trim($location) : null;
        $location = $location !== '' ? $location : null;
        $description = $description !== null ? trim($description) : null;
        $description = $description !== '' ? $description : null;
        $eventDate = TrackingEventDate::normalize($eventDate);

        $barangs = $this->relationLoaded('barangs')
            ? $this->barangs
            : $this->barangs()->get();

        $fixed = 0;

        foreach ($barangs as $barang) {
            if (Barang::isDitarikKembaliStatus($barang->status)) {
                continue;
            }

            $currentStatus = (string) $barang->status;
            $currentRank = self::barangShipmentProgressRank($currentStatus);

            if ($onlyUpgrade && $currentRank > $expectedRank) {
                continue;
            }

            if ($currentStatus === $barangStatus) {
                if (! $onlyUpgrade) {
                    $barang->syncShipmentTrackingStatus(
                        $barangStatus,
                        $location,
                        $description,
                        $createdBy,
                        $eventDate,
                    );
                }

                continue;
            }

            $barang->update(['status' => $barangStatus]);
            $barang->syncShipmentTrackingStatus(
                $barangStatus,
                $location,
                $description,
                $createdBy,
                $eventDate,
            );
            $fixed++;
        }

        return $fixed;
    }

    /**
     * Upgrade lagging barangs to match current advanced container tracking (ETD/ETA/bongkar).
     *
     * @return int Number of barangs fixed
     */
    public function alignLaggingBarangsToCurrentTracking(?int $createdBy = null): int
    {
        $trackingStatus = $this->trackingStatusBeyondLoading();
        if ($trackingStatus === null) {
            return 0;
        }

        $latest = $this->relationLoaded('latestTracking')
            ? $this->latestTracking
            : $this->trackingStatuses()->first();

        $this->unsetRelation('barangs');

        return $this->cascadeTrackingToBarangs(
            $trackingStatus,
            $latest?->location,
            $latest?->description,
            $createdBy,
            onlyUpgrade: true,
            eventDate: TrackingEventDate::normalize($latest?->event_date)
                ?? TrackingEventDate::normalize($latest?->created_at),
        );
    }

    public function syncEtdTracking(
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
    ): ?ContainerTrackingStatus {
        return $this->syncTrackingStatus(self::TRACKING_ETD, $location, $description, $createdBy, $currentStatus);
    }

    public function syncEtaTracking(
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
    ): ?ContainerTrackingStatus {
        return $this->syncTrackingStatus(self::TRACKING_ETA, $location, $description, $createdBy, $currentStatus);
    }

    public function syncBeaCukaiTracking(
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
    ): ?ContainerTrackingStatus {
        return $this->syncTrackingStatus(self::TRACKING_BEA_CUKAI, $location, $description, $createdBy, $currentStatus);
    }

    public function syncBongkarTracking(
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
    ): ?ContainerTrackingStatus {
        return $this->syncTrackingStatus(self::TRACKING_BONGKAR, $location, $description, $createdBy, $currentStatus);
    }

    public function syncTrackingStatus(
        string $trackingStatus,
        ?string $location = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?string $currentStatus = null,
        ?string $eventDate = null,
    ): ?ContainerTrackingStatus {
        $payload = $this->resolveTrackingPayload($trackingStatus, $location, $description, $currentStatus);
        $eventDate = TrackingEventDate::resolve($eventDate);
        $latestColumns = ['id', 'status', 'location', 'description', 'created_at'];
        if (TrackingEventDate::containerColumnReady()) {
            $latestColumns[] = 'event_date';
        }
        $latest = $this->trackingStatuses()
            ->select($latestColumns)
            ->first();

        if (($this->status ?? null) !== $payload['current_status']) {
            $this->forceFill(['status' => $payload['current_status']])->save();
        }

        if (
            $latest !== null
            && $latest->status === $payload['status']
            && trim((string) ($latest->location ?? '')) === ($payload['location'] ?? '')
            && trim((string) ($latest->description ?? '')) === ($payload['description'] ?? '')
        ) {
            $latestEvent = TrackingEventDate::normalize($latest->event_date) ?? TrackingEventDate::normalize($latest->created_at);
            if ($latestEvent !== $eventDate && TrackingEventDate::containerColumnReady()) {
                $latest->forceFill(['event_date' => $eventDate])->save();
            }

            return $latest->fresh();
        }

        $now = now();
        $insert = [
            'container_id' => $this->id,
            'status' => $payload['status'],
            'location' => $payload['location'],
            'description' => $payload['description'],
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (TrackingEventDate::containerColumnReady()) {
            $insert['event_date'] = $eventDate;
        }

        $id = (int) DB::table('container_tracking_statuses')->insertGetId($insert);

        return ContainerTrackingStatus::query()->find($id);
    }

    /**
     * Update a specific container timeline row (status, location, description, event date).
     * Recomputes container current status from the newest remaining entry and realigns barangs.
     */
    public function updateTrackingTimelineEntry(
        ContainerTrackingStatus $entry,
        string $trackingStatus,
        ?string $location,
        ?string $description,
        ?string $eventDate,
        ?int $actorId = null,
    ): ContainerTrackingStatus {
        if ((int) $entry->container_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Timeline entry does not belong to this container.');
        }

        $oldStatus = (string) $entry->status;
        $oldLocation = $entry->location !== null ? trim((string) $entry->location) : null;
        $oldLocation = $oldLocation !== '' ? $oldLocation : null;
        $oldDescription = $entry->description !== null ? trim((string) $entry->description) : null;
        $oldDescription = $oldDescription !== '' ? $oldDescription : null;
        $oldEventDate = TrackingEventDate::normalize($entry->event_date)
            ?? TrackingEventDate::normalize($entry->created_at);

        $payload = $this->resolveTrackingPayload($trackingStatus, $location, $description);
        $resolvedEventDate = TrackingEventDate::resolve($eventDate);

        $fill = [
            'status' => $payload['status'],
            'location' => $payload['location'],
            'description' => $payload['description'],
        ];
        if (TrackingEventDate::containerColumnReady()) {
            $fill['event_date'] = $resolvedEventDate;
        }
        $entry->forceFill($fill)->save();

        $this->loadMissing('barangs');
        $this->rewriteMatchingBarangTimelineEntries(
            oldTrackingStatus: $oldStatus,
            oldLocation: $oldLocation,
            oldDescription: $oldDescription,
            oldEventDate: $oldEventDate,
            newTrackingStatus: $payload['status'],
            newLocation: $payload['location'],
            newDescription: $payload['description'],
            newEventDate: $resolvedEventDate,
        );

        $this->refreshCurrentStatusFromLatestTimeline($actorId);

        return $entry->fresh() ?? $entry;
    }

    /**
     * Delete a container timeline row, remove matching cascaded barang rows, then fall back display/status.
     */
    public function deleteTrackingTimelineEntry(
        ContainerTrackingStatus $entry,
        ?int $actorId = null,
    ): void {
        if ((int) $entry->container_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Timeline entry does not belong to this container.');
        }

        $oldStatus = (string) $entry->status;
        $oldLocation = $entry->location !== null ? trim((string) $entry->location) : null;
        $oldLocation = $oldLocation !== '' ? $oldLocation : null;
        $oldDescription = $entry->description !== null ? trim((string) $entry->description) : null;
        $oldDescription = $oldDescription !== '' ? $oldDescription : null;
        $oldEventDate = TrackingEventDate::normalize($entry->event_date)
            ?? TrackingEventDate::normalize($entry->created_at);

        $entry->delete();

        $this->loadMissing('barangs');
        $this->rewriteMatchingBarangTimelineEntries(
            oldTrackingStatus: $oldStatus,
            oldLocation: $oldLocation,
            oldDescription: $oldDescription,
            oldEventDate: $oldEventDate,
            newTrackingStatus: null,
            newLocation: null,
            newDescription: null,
            newEventDate: null,
        );

        $this->refreshCurrentStatusFromLatestTimeline($actorId);
    }

    /**
     * @param  ?string  $newTrackingStatus  null = delete matching barang rows
     */
    private function rewriteMatchingBarangTimelineEntries(
        string $oldTrackingStatus,
        ?string $oldLocation,
        ?string $oldDescription,
        ?string $oldEventDate,
        ?string $newTrackingStatus,
        ?string $newLocation,
        ?string $newDescription,
        ?string $newEventDate,
    ): void {
        $oldBarangStatus = self::barangStatusForTrackingStatus($oldTrackingStatus);
        $newBarangStatus = $newTrackingStatus !== null
            ? self::barangStatusForTrackingStatus($newTrackingStatus)
            : null;

        foreach ($this->barangs as $barang) {
            if (Barang::isDitarikKembaliStatus($barang->status)) {
                continue;
            }

            $match = $this->findMatchingBarangTimelineEntry(
                $barang,
                $oldBarangStatus,
                $oldLocation,
                $oldDescription,
                $oldEventDate,
            );

            if ($match === null) {
                continue;
            }

            if ($newBarangStatus === null) {
                $match->delete();

                continue;
            }

            $fill = [
                'status' => $newBarangStatus,
                'location' => $newLocation,
                'description' => $newDescription,
            ];
            if (TrackingEventDate::barangColumnReady()) {
                $fill['event_date'] = TrackingEventDate::resolve($newEventDate);
            }
            $match->forceFill($fill)->save();
        }
    }

    private function findMatchingBarangTimelineEntry(
        Barang $barang,
        string $barangStatus,
        ?string $location,
        ?string $description,
        ?string $eventDate,
    ): ?TrackingStatus {
        $query = TrackingStatus::query()
            ->where('barang_id', $barang->id)
            ->where('status', $barangStatus);

        if ($location === null) {
            $query->where(static function ($q): void {
                $q->whereNull('location')->orWhere('location', '');
            });
        } else {
            $query->where('location', $location);
        }

        if ($description === null) {
            $query->where(static function ($q): void {
                $q->whereNull('description')->orWhere('description', '');
            });
        } else {
            $query->where('description', $description);
        }

        if ($eventDate !== null) {
            if (TrackingEventDate::barangColumnReady()) {
                $query->where(static function ($q) use ($eventDate): void {
                    $q->whereDate('event_date', $eventDate)
                        ->orWhere(static function ($legacy) use ($eventDate): void {
                            $legacy->whereNull('event_date')->whereDate('created_at', $eventDate);
                        });
                });
            } else {
                $query->whereDate('created_at', $eventDate);
            }
        }

        return $query->orderByDesc('id')->first();
    }

    private function refreshCurrentStatusFromLatestTimeline(?int $actorId = null): void
    {
        $this->unsetRelation('latestTracking');
        $this->unsetRelation('trackingStatuses');

        $latest = $this->trackingStatuses()->first();

        if ($latest === null) {
            $fallback = $this->barangs()->exists() ? self::STATUS_LOADING : self::STATUS_OPEN;
            if (($this->status ?? null) !== $fallback) {
                $this->forceFill(['status' => $fallback])->save();
            }

            foreach ($this->barangs as $barang) {
                if (Barang::isDitarikKembaliStatus($barang->status)) {
                    continue;
                }

                $latestBarang = $barang->trackingStatuses()->first();
                $nextStatus = $latestBarang !== null
                    ? Barang::normalizeShipmentStatus((string) $latestBarang->status)
                    : Barang::STATUS_STUFFING;

                if ((string) $barang->status !== $nextStatus) {
                    $barang->update(['status' => $nextStatus]);
                }
            }

            return;
        }

        $payload = [
            'status' => (string) $latest->status,
            'location' => $latest->location !== null && trim((string) $latest->location) !== ''
                ? trim((string) $latest->location)
                : null,
            'description' => $latest->description !== null && trim((string) $latest->description) !== ''
                ? trim((string) $latest->description)
                : null,
            'current_status' => self::currentStatusForTrackingStatus((string) $latest->status),
        ];

        if (($this->status ?? null) !== $payload['current_status']) {
            $this->forceFill(['status' => $payload['current_status']])->save();
        }

        $this->unsetRelation('barangs');
        $this->load('barangs');
        $this->cascadeTrackingToBarangs(
            $payload['status'],
            $payload['location'],
            $payload['description'],
            $actorId,
            onlyUpgrade: false,
            eventDate: TrackingEventDate::normalize($latest->event_date)
                ?? TrackingEventDate::normalize($latest->created_at),
        );
    }

    public static function currentStatusForTrackingStatus(string $trackingStatus): string
    {
        return match ($trackingStatus) {
            self::TRACKING_LOADING => self::STATUS_LOADING,
            self::TRACKING_ETD => self::STATUS_SHIPPED,
            self::TRACKING_ETA => self::STATUS_ARRIVED,
            self::TRACKING_BEA_CUKAI => self::STATUS_ARRIVED,
            self::TRACKING_BONGKAR => self::STATUS_UNLOADING,
            default => self::STATUS_OPEN,
        };
    }

    public static function barangStatusForTrackingStatus(string $trackingStatus): string
    {
        return match ($trackingStatus) {
            self::TRACKING_LOADING => Barang::STATUS_STUFFING,
            self::TRACKING_ETD => Barang::STATUS_ETD,
            self::TRACKING_ETA => Barang::STATUS_ETA,
            self::TRACKING_BEA_CUKAI => Barang::STATUS_ETA,
            self::TRACKING_BONGKAR => Barang::STATUS_BONGKAR,
            default => Barang::STATUS_STUFFING,
        };
    }

    public static function trackingStatusFromCurrentState(string $currentStatus, ?Collection $barangs = null): ?string
    {
        return match ($currentStatus) {
            self::STATUS_LOADING => self::TRACKING_LOADING,
            self::STATUS_SHIPPED => self::TRACKING_ETD,
            self::STATUS_ARRIVED => self::TRACKING_ETA,
            self::STATUS_UNLOADING => self::allBarangsDelivered($barangs) ? self::TRACKING_BONGKAR : self::TRACKING_ETA,
            self::STATUS_CLOSED => self::TRACKING_BONGKAR,
            default => null,
        };
    }

    public static function trackingStatusLabel(string $status): string
    {
        $key = 'tracking.container_status_'.$status;

        return __($key) !== $key ? __($key) : ucfirst($status);
    }

    public static function trackingStatusBadge(?string $status): string
    {
        return match ($status) {
            self::TRACKING_DITARIK_KEMBALI => 'badge-status-ditarik_kembali',
            self::TRACKING_LOADING => 'badge-status-stuffing',
            self::TRACKING_ETD => 'badge-status-etd',
            self::TRACKING_ETA => 'badge-status-eta',
            self::TRACKING_BEA_CUKAI => 'badge-status-eta',
            self::TRACKING_BONGKAR => 'badge-status-bongkar',
            default => 'badge-status-draft',
        };
    }

    private function defaultTrackingLocation(string $trackingStatus): string
    {
        if ($trackingStatus === self::TRACKING_LOADING) {
            $location = trim((string) ($this->gudangChina?->name ?? ''));
            if ($location !== '') {
                return $location;
            }

            $address = trim((string) ($this->alamat_gudang_china ?? ''));

            return $address !== '' ? $address : (string) __('tracking.auto_container_loading_location_fallback');
        }

        return match ($trackingStatus) {
            self::TRACKING_ETD => (string) __('tracking.auto_etd_location_fallback'),
            self::TRACKING_ETA => (string) __('tracking.auto_eta_location_fallback'),
            self::TRACKING_BEA_CUKAI => (string) __('tracking.auto_bea_cukai_location_fallback'),
            self::TRACKING_BONGKAR => (string) __('tracking.auto_bongkar_location_fallback'),
            default => '-',
        };
    }

    private function defaultTrackingDescription(string $trackingStatus): string
    {
        return match ($trackingStatus) {
            self::TRACKING_LOADING => (string) __('tracking.auto_container_loading_description', [
                'container' => $this->partai ?: ($this->container_no ?: '#'.$this->id),
            ]),
            self::TRACKING_ETD => (string) __('tracking.auto_container_etd_description'),
            self::TRACKING_ETA => (string) __('tracking.auto_container_eta_description'),
            self::TRACKING_BEA_CUKAI => (string) __('tracking.auto_container_bea_cukai_description'),
            self::TRACKING_BONGKAR => (string) __('tracking.auto_container_bongkar_description'),
            default => '',
        };
    }

    private static function allBarangsDelivered(?Collection $barangs): bool
    {
        if ($barangs === null || $barangs->isEmpty()) {
            return false;
        }

        return $barangs->every(static fn (Barang $barang): bool => $barang->status === Barang::STATUS_BONGKAR);
    }
}
