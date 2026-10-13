<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserNotification extends Model
{
    public const TYPE_BARANG_CREATED = 'barang_created';

    public const TYPE_DELIVERY_TRIP_CREATED = 'delivery_trip_created';

    public const TYPE_REPRICING_SUBMITTED = 'repricing_submitted';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function url(): ?string
    {
        $url = $this->data['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function iconClass(): string
    {
        return match ($this->type) {
            self::TYPE_BARANG_CREATED => 'bi-box-seam-fill text-accent',
            self::TYPE_DELIVERY_TRIP_CREATED => 'bi-truck-front-fill text-warning',
            self::TYPE_REPRICING_SUBMITTED => 'bi-tags-fill text-info',
            default => 'bi-bell-fill text-muted',
        };
    }
}
