<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use App\Support\CompanyRevenueSettings;

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

    public function pricingItems(): BelongsToMany
    {
        return $this->belongsToMany(
            MasterPricingItem::class,
            'sales_incentive_tier_pricing_item',
            'sales_incentive_tier_id',
            'pricing_item_id',
        )->withTimestamps();
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

        if (! Schema::hasTable('sales_incentive_tier_pricing_item')) {
            return $query->where('pricing_item_id', $pricingItemId);
        }

        return $query->where(function ($inner) use ($pricingItemId) {
            $inner->whereHas(
                'pricingItems',
                static fn ($pricing) => $pricing->where('master_pricing_items.id', $pricingItemId),
            )->orWhere(function ($legacy) use ($pricingItemId) {
                $legacy->whereDoesntHave('pricingItems')
                    ->where('pricing_item_id', $pricingItemId);
            });
        });
    }

    /**
     * @param  list<int>  $pricingItemIds
     */
    public function syncPricingItemIds(array $pricingItemIds): void
    {
        $ids = collect($pricingItemIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->pricingItems()->sync($ids);

        $this->forceFill([
            'pricing_item_id' => $ids[0] ?? null,
        ])->save();
    }

    /** @return list<int> */
    public function resolvedPricingItemIds(): array
    {
        $pivotIds = $this->relationLoaded('pricingItems')
            ? $this->pricingItems->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : $this->pricingItems()->pluck('master_pricing_items.id')->map(static fn ($id): int => (int) $id)->all();

        if ($pivotIds !== []) {
            return array_values(array_unique($pivotIds));
        }

        $legacy = (int) ($this->pricing_item_id ?? 0);

        return $legacy > 0 ? [$legacy] : [];
    }

    public function coversAllModalCategories(): bool
    {
        $modalIds = CompanyRevenueSettings::modalPricingItemIds();
        if ($modalIds === []) {
            return false;
        }

        $selected = $this->resolvedPricingItemIds();
        if ($selected === []) {
            return false;
        }

        $modalSorted = $modalIds;
        sort($modalSorted);
        $selectedSorted = array_values(array_unique($selected));
        sort($selectedSorted);

        return $selectedSorted === $modalSorted;
    }

    public function resolvedPricingItemNames(): string
    {
        if ($this->coversAllModalCategories()) {
            return (string) __('common.all');
        }

        if ($this->relationLoaded('pricingItems') && $this->pricingItems->isNotEmpty()) {
            return $this->pricingItems
                ->pluck('name')
                ->filter()
                ->values()
                ->implode(', ');
        }

        $ids = $this->resolvedPricingItemIds();
        if ($ids === []) {
            return '-';
        }

        /** @var Collection<int, string> $names */
        $names = MasterPricingItem::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name');

        return $names->filter()->values()->implode(', ') ?: '-';
    }
}
