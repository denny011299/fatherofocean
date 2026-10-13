<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'barang_id',
        'cbm',
        'price_per_cbm',
        'insurance',
        'extra',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'cbm' => 'decimal:4',
            'price_per_cbm' => 'decimal:2',
            'insurance' => 'decimal:2',
            'extra' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item) {
            $item->line_total = ($item->cbm * $item->price_per_cbm) + $item->insurance + $item->extra;
        });
    }
}
