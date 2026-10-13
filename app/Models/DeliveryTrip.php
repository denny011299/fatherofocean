<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeliveryTrip extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_DEPARTED = 'departed';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [
        self::STATUS_READY,
        self::STATUS_DEPARTED,
        self::STATUS_ARRIVED,
    ];

    protected $fillable = [
        'trip_no',
        'container_id',
        'truck_plate',
        'driver_name',
        'driver_phone',
        'destination',
        'notes',
        'status',
        'departed_at',
        'departed_confirmed_by',
        'arrived_at',
        'arrived_confirmed_by',
        'print_marking_code',
        'print_customer_name',
        'print_customer_phone',
        'print_customer_address',
        'print_loading_date',
        'print_item_name',
        'print_total_coly',
        'print_partai',
        'print_notes',
        'receiver_name',
        'receiver_phone',
        'received_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'print_loading_date' => 'date',
            'received_date' => 'date',
        ];
    }

    public static function generateTripNo(): string
    {
        $prefix = 'SJ-'.now()->format('Ymd').'-';

        do {
            $candidate = $prefix.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::query()->where('trip_no', $candidate)->exists());

        return $candidate;
    }

    public static function statusLabel(?string $status): string
    {
        return match ((string) $status) {
            self::STATUS_DRAFT => __('delivery_trip.status_draft'),
            self::STATUS_READY => __('delivery_trip.status_ready'),
            self::STATUS_DEPARTED => __('delivery_trip.status_departed'),
            self::STATUS_ARRIVED => __('delivery_trip.status_arrived'),
            self::STATUS_COMPLETED => __('delivery_trip.status_completed'),
            self::STATUS_CANCELLED => __('delivery_trip.status_cancelled'),
            default => ucfirst((string) $status),
        };
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departedConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departed_confirmed_by');
    }

    public function arrivedConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arrived_confirmed_by');
    }

    public function barangs(): BelongsToMany
    {
        return $this->belongsToMany(Barang::class, 'delivery_trip_barang')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function media(): HasMany
    {
        return $this->hasMany(DeliveryTripMedia::class);
    }

    public function markingSummaries(): HasMany
    {
        return $this->hasMany(DeliveryTripMarkingSummary::class);
    }

    public function departureMedia(): HasMany
    {
        return $this->media()->where('phase', DeliveryTripMedia::PHASE_DEPARTURE);
    }

    public function arrivalMedia(): HasMany
    {
        return $this->media()->where('phase', DeliveryTripMedia::PHASE_ARRIVAL);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function canEdit(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_READY], true);
    }

    public function canManageSummary(): bool
    {
        return $this->status !== self::STATUS_CANCELLED;
    }

    public function canConfirmDeparture(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_READY], true);
    }

    public function canConfirmArrival(): bool
    {
        return $this->status === self::STATUS_DEPARTED;
    }

    public function canGeneratePdf(): bool
    {
        return in_array($this->status, [
            self::STATUS_READY,
            self::STATUS_DEPARTED,
            self::STATUS_ARRIVED,
            self::STATUS_COMPLETED,
        ], true);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_READY => 'badge-status-pending',
            self::STATUS_DEPARTED => 'badge-status-etd',
            self::STATUS_ARRIVED => 'badge-status-eta',
            self::STATUS_COMPLETED => 'badge-status-bongkar',
            self::STATUS_CANCELLED => 'badge-status-rejected',
            default => 'badge-glass',
        };
    }
}
