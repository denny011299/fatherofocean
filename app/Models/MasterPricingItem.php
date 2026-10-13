<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterPricingItem extends Model
{
    protected $fillable = [
        'name',
        'volume_price',
        'gw_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'volume_price' => 'decimal:2',
            'gw_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function markingPricings(): HasMany
    {
        return $this->hasMany(MarkingPricing::class, 'pricing_item_id');
    }
}
