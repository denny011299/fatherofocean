<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesIncentiveLine extends Model
{
    protected $fillable = [
        'sales_id',
        'barang_id',
        'pricing_item_id',
        'period',
        'total_cbm',
        'paid_cbm',
        'stuffed_at',
        'invoice_paid_at',
    ];

    protected function casts(): array
    {
        return [
            'pricing_item_id' => 'integer',
            'total_cbm' => 'decimal:4',
            'paid_cbm' => 'decimal:4',
            'stuffed_at' => 'datetime',
            'invoice_paid_at' => 'datetime',
        ];
    }

    public function pricingItem(): BelongsTo
    {
        return $this->belongsTo(MasterPricingItem::class, 'pricing_item_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
