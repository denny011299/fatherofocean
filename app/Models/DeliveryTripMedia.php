<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryTripMedia extends Model
{
    public const PHASE_DEPARTURE = 'departure';

    public const PHASE_ARRIVAL = 'arrival';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_VIDEO = 'video';

    protected $fillable = [
        'delivery_trip_id',
        'marking_key',
        'phase',
        'media_type',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DeliveryTrip::class, 'delivery_trip_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        return PublicStorageUrl::url($this->file_path);
    }

    public function isVideo(): bool
    {
        return $this->media_type === self::TYPE_VIDEO;
    }
}
