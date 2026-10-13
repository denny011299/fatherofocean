<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TrackingEventDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContainerTrackingStatus extends Model
{
    protected $fillable = [
        'container_id',
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

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
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
