<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryTripMarkingSummary extends Model
{
    protected $fillable = [
        'delivery_trip_id',
        'marking_key',
        'truck_plate',
        'driver_name',
        'driver_phone',
        'destination',
        'trip_notes',
        'status',
        'departed_at',
        'departed_confirmed_by',
        'arrived_at',
        'arrived_confirmed_by',
        'marking_code',
        'customer_name',
        'customer_phone',
        'customer_address',
        'loading_date',
        'item_name',
        'total_coly',
        'partai',
        'notes',
        'receiver_name',
        'receiver_phone',
        'received_date',
        'split_deliveries',
    ];

    protected function casts(): array
    {
        return [
            'loading_date' => 'date',
            'received_date' => 'date',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'split_deliveries' => 'array',
        ];
    }

    public function deliveryTrip(): BelongsTo
    {
        return $this->belongsTo(DeliveryTrip::class);
    }

    public function truckSaved(): bool
    {
        return trim((string) ($this->truck_plate ?? '')) !== ''
            && trim((string) ($this->driver_name ?? '')) !== '';
    }

    public function canConfirmDeparture(): bool
    {
        return $this->truckSaved()
            && in_array((string) ($this->status ?? DeliveryTrip::STATUS_READY), [DeliveryTrip::STATUS_DRAFT, DeliveryTrip::STATUS_READY], true);
    }

    public function canConfirmArrival(): bool
    {
        return (string) ($this->status ?? '') === DeliveryTrip::STATUS_DEPARTED;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            DeliveryTrip::STATUS_READY => 'badge-status-pending',
            DeliveryTrip::STATUS_DEPARTED => 'badge-status-etd',
            DeliveryTrip::STATUS_ARRIVED => 'badge-status-eta',
            DeliveryTrip::STATUS_COMPLETED => 'badge-status-bongkar',
            DeliveryTrip::STATUS_CANCELLED => 'badge-status-rejected',
            default => 'badge-glass',
        };
    }
}
