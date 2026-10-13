<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class DeliveryTrip extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_DEPARTED = 'departed';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_COMPLETED = 'completed';

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
        'created_by',
    ];

    public function barangs(): BelongsToMany
    {
        return $this->belongsToMany(Barang::class, 'delivery_trip_barang')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
