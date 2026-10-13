<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TrackingEventDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TrackingStatus extends Model
{
    protected $fillable = [
        'barang_id',
        'status',
        'location',
        'description',
        'event_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayAt(): ?Carbon
    {
        return TrackingEventDate::displayAt($this->event_date, $this->created_at);
    }
}
