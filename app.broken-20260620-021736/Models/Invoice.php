<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class Invoice extends Model
{
    protected $fillable = [
        'invoice_no',
        'user_id',
        'total_cbm',
        'price_per_cbm',
        'subtotal',
        'insurance_total',
        'extra_charges',
        'grand_total',
        'status',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total_cbm' => 'decimal:4',
            'price_per_cbm' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'insurance_total' => 'decimal:2',
            'extra_charges' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function recalculate(): void
    {
        $items = $this->items;
        $this->total_cbm = $items->sum('cbm');
        $this->subtotal = $items->sum(fn ($i) => $i->cbm * $i->price_per_cbm);
        $this->insurance_total = $items->sum('insurance');
        $this->extra_charges = $items->sum('extra');
        $this->grand_total = $this->subtotal + $this->insurance_total + $this->extra_charges;
        $this->save();
    }

    public static function generateNumber(): string
    {
        return DB::transaction(function () {
            $prefix = 'INV-' . now()->format('Ymd');
            $last = static::where('invoice_no', 'like', $prefix . '%')
                ->lockForUpdate()
                ->max('invoice_no');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return $prefix . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }
}
