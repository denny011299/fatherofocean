<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class District extends Model
{
    protected $fillable = ['city_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($q) { return $q->where('is_active', true); }

    public function city(): BelongsTo { return $this->belongsTo(City::class); }
}
