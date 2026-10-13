<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RepricingRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const MODE_CBM = 'cbm';

    public const MODE_WEIGHT = 'weight';

    public const MODE_SPLIT = 'split';

    protected $fillable = [
        'barang_id',
        'submitted_by',
        'reviewed_by',
        'status',
        'pricing_mode',
        'old_price_per_cbm',
        'old_extra_charge',
        'old_weight_price',
        'old_minimum_charge_cbm',
        'old_weight_threshold_kg',
        'new_price_per_cbm',
        'new_extra_charge',
        'new_weight_price',
        'new_minimum_charge_cbm',
        'new_weight_threshold_kg',
        'rejection_note',
        'reason',
        'proof_path',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price_per_cbm' => 'decimal:2',
            'old_extra_charge' => 'decimal:2',
            'old_weight_price' => 'decimal:2',
            'old_minimum_charge_cbm' => 'decimal:2',
            'old_weight_threshold_kg' => 'decimal:2',
            'new_price_per_cbm' => 'decimal:2',
            'new_extra_charge' => 'decimal:2',
            'new_weight_price' => 'decimal:2',
            'new_minimum_charge_cbm' => 'decimal:2',
            'new_weight_threshold_kg' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Pending di atas, lalu terbaru berdasarkan submitted_at. */
    public function scopeOrderForReviewQueue(Builder $query): Builder
    {
        return $query
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED],
            )
            ->latest('submitted_at');
    }

    public function usesWeightPricing(): bool
    {
        return $this->pricing_mode === self::MODE_WEIGHT;
    }

    public function usesSplitPricing(): bool
    {
        return $this->pricing_mode === self::MODE_SPLIT;
    }

    /**
     * @return array<string, mixed>
     */
    public function pricingLogContext(): array
    {
        $this->loadMissing('barang', 'submitter');

        return [
            'repricing_request_id' => $this->id,
            'marking' => $this->barang?->marking,
            'no_index' => $this->barang?->no_index,
            'submitted_by' => $this->submitter?->name,
            'pricing_mode' => $this->pricing_mode,
            'price_per_cbm' => $this->new_price_per_cbm,
            'extra_charge' => $this->new_extra_charge,
            'weight_price' => $this->new_weight_price,
            'minimum_charge_cbm' => $this->new_minimum_charge_cbm,
            'weight_threshold_kg' => $this->new_weight_threshold_kg,
            'reason' => $this->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function oldPricingLogContext(): array
    {
        $this->loadMissing('barang');

        return [
            'repricing_request_id' => $this->id,
            'marking' => $this->barang?->marking,
            'no_index' => $this->barang?->no_index,
            'pricing_mode' => $this->pricing_mode,
            'price_per_cbm' => $this->old_price_per_cbm,
            'extra_charge' => $this->old_extra_charge,
            'weight_price' => $this->old_weight_price,
            'minimum_charge_cbm' => $this->old_minimum_charge_cbm,
            'weight_threshold_kg' => $this->old_weight_threshold_kg,
        ];
    }
}
