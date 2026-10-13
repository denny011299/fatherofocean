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
            'cbm_min' => 'float',
            'cbm_max' => 'float',
            'cbm_per_block' => 'float',
            'amount_per_block' => 'float',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function pricingItem(): BelongsTo
    {
        return $this->belongsTo(MasterPricingItem::class, 'pricing_item_id');
    }
}
