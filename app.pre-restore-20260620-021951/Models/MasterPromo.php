<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterPromo extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED_RP = 'fixed_rp';

    protected $table = 'master_promos';

    protected $fillable = [
        'name',
        'type',
        'persen',
        'amount_rp',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'persen' => 'decimal:2',
            'amount_rp' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'promo_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isFixedRp(): bool
    {
        return ($this->type ?? self::TYPE_PERCENT) === self::TYPE_FIXED_RP;
    }

    public function formattedDiscountValue(): string
    {
        if ($this->isFixedRp()) {
            return 'Rp '.number_format((float) ($this->amount_rp ?? 0), 0, ',', '.');
        }

        return number_format((float) ($this->persen ?? 0), 2, ',', '.').'%';
    }

    public function calculateDiscount(float $beforeDiscount): float
    {
        if ($this->isFixedRp()) {
            $amount = (float) ($this->amount_rp ?? 0);

            return $amount > 0 ? min($amount, $beforeDiscount) : 0.0;
        }

        $persen = (float) ($this->persen ?? 0);

        return $persen > 0 ? round($beforeDiscount * ($persen / 100), 2) : 0.0;
    }

    /**
     * @return array{type: string, persen: ?float, amount_rp: ?float}
     */
    public function snapshotForInvoice(): array
    {
        if ($this->isFixedRp()) {
            return [
                'type' => self::TYPE_FIXED_RP,
                'persen' => null,
                'amount_rp' => $this->amount_rp !== null ? (float) $this->amount_rp : null,
            ];
        }

        return [
            'type' => self::TYPE_PERCENT,
            'persen' => $this->persen !== null ? (float) $this->persen : null,
            'amount_rp' => null,
        ];
    }
}
