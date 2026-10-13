<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MarkingPricing extends Model
{
    protected $fillable = [
        'marking_id', 'pricing_item_id', 'volume_price', 'gw_price',
        'minimum_charge', 'effective_start', 'invoice_due_days', 'special_volume',
    ];

    protected function casts(): array
    {
        return [
            'volume_price'    => 'decimal:2',
            'gw_price'        => 'decimal:2',
            'minimum_charge'  => 'decimal:2',
            'special_volume'  => 'decimal:2',
            'effective_start' => 'date',
            'invoice_due_days'=> 'integer',
        ];
    }

    public function marking(): BelongsTo { return $this->belongsTo(Marking::class); }

    public function pricingItem(): BelongsTo { return $this->belongsTo(MasterPricingItem::class, 'pricing_item_id'); }
}
