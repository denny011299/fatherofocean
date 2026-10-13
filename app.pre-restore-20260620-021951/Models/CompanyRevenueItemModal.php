<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyRevenueItemModal extends Model
{
    protected $fillable = [
        'pricing_item_id',
        'cost_volume_price',
        'cost_gw_price',
    ];

    protected function casts(): array
    {
        return [
            'cost_volume_price' => 'decimal:2',
            'cost_gw_price' => 'decimal:2',
        ];
    }

    public function pricingItem(): BelongsTo
    {
        return $this->belongsTo(MasterPricingItem::class, 'pricing_item_id');
    }
}
