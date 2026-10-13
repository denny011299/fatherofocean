<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesIncentiveTier extends Model
{
    protected $fillable = [
        'name',
        'pricing_item_id',
        'cbm_min',
        'cbm_max',
        'cbm_per_block',
        'amount_per_block',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pricing_item_id' => 'integer',
            'cbm_min' => 'decimal:4',
            'cbm_max' => 'decimal:4',
            'cbm_per_block' => 'decimal:4',
            'amount_per_block' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function pricingItem(): BelongsTo
    {
        return $this->belongsTo(MasterPricingItem::class, 'pricing_item_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPricingItem($query, ?int $pricingItemId)
    {
        if ($pricingItemId === null || $pricingItemId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('pricing_item_id', $pricingItemId);
    }
}
