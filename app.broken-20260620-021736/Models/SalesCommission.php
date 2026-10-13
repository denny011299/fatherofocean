<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesCommission extends Model
{
    public const STATUS_WAITING = 'waiting';

    /** @deprecated Gunakan {@see STATUS_FIXED} — invoice lunas, net otomatis */
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CAIR = 'cair';

    /** Net fix setelah invoice lunas (tanpa ACC manual). */
    public const STATUS_FIXED = 'fixed';

    protected $fillable = [
        'barang_id',
        'sales_id',
        'supervisor_id',
        'total_cbm',
        'billable_base',
        'company_profit_amount',
        'sales_commission_base',
        'cost_subtotal',
        'markup_amount',
        'supervisor_amount',
        'commission_rate',
        'estimated_amount',
        'confirmed_amount',
        'status',
        'confirmed_by',
        'confirmed_at',
        'paid_at',
        'settled_at',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'total_cbm' => 'decimal:4',
            'billable_base' => 'decimal:2',
            'company_profit_amount' => 'decimal:2',
            'sales_commission_base' => 'decimal:2',
            'cost_subtotal' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'supervisor_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'estimated_amount' => 'decimal:2',
            'confirmed_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_FIXED,
            self::STATUS_CONFIRMED,
            self::STATUS_CAIR,
        ], true);
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }
}
