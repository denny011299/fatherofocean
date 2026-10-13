<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CbmFormat;
use App\Support\CbmVolume;
use App\Support\RmbFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class BarangProduct extends Model
{
    protected $fillable = [
        'barang_id',
        'parent_id',
        'sort_order',
        'ctns',
        'product_name',
        'photo_path',
        'material',
        'pcs_per_box',
        'gross_weight_kg',
        'length_cm',
        'length_photo_path',
        'width_cm',
        'width_photo_path',
        'height_cm',
        'height_photo_path',
        'volume_cbm',
        'price_per_unit_rmb',
        'total_price_rmb',
    ];

    protected function casts(): array
    {
        return [
            'gross_weight_kg' => 'decimal:2',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'volume_cbm' => 'decimal:6',
            'price_per_unit_rmb' => 'decimal:3',
            'total_price_rmb' => 'decimal:3',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isSubItem(): bool
    {
        return $this->parent_id !== null;
    }

    /** Box logistics (CTNS, GW, dimensions, CBM) — sub-items inherit from parent row. */
    public function logisticsSource(): self
    {
        if (! $this->isSubItem()) {
            return $this;
        }

        if ($this->relationLoaded('parent') && $this->parent instanceof self) {
            return $this->parent;
        }

        $parent = self::query()->find($this->parent_id);

        return $parent ?? $this;
    }

    /**
     * Parent box rows first, then their sub-items — matches the product form layout.
     *
     * @param  Collection<int, self>|iterable<self>  $products
     * @return Collection<int, self>
     */
    public static function orderedForForm(iterable $products): Collection
    {
        $items = $products instanceof Collection
            ? $products->values()
            : collect($products)->values();

        if ($items->isEmpty()) {
            return $items;
        }

        $parents = $items
            ->filter(static fn (self $product): bool => $product->parent_id === null)
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $subsByParent = $items
            ->filter(static fn (self $product): bool => $product->parent_id !== null)
            ->groupBy('parent_id');

        $ordered = collect();
        $used = [];

        foreach ($parents as $parent) {
            $ordered->push($parent);
            $used[$parent->id] = true;

            $subs = $subsByParent->get($parent->id) ?? collect();
            foreach ($subs->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]) as $sub) {
                $ordered->push($sub);
                $used[$sub->id] = true;
            }
        }

        foreach ($items as $item) {
            if (! isset($used[$item->id])) {
                $ordered->push($item);
            }
        }

        return $ordered->values();
    }

    /** CBM per coly: P×L×T / 1.000.000 (dari dimensi, bukan kolom volume_cbm). */
    public function resolvedUnitCbm(): float
    {
        $src = $this->logisticsSource();

        return CbmVolume::unit(
            (float) $src->length_cm,
            (float) $src->width_cm,
            (float) $src->height_cm,
        );
    }

    /** Total CBM baris: P×L×T×CTNS / 1.000.000 (dari dimensi). */
    public function resolvedLineCbm(): float
    {
        $src = $this->logisticsSource();

        return CbmVolume::line(
            (float) $src->length_cm,
            (float) $src->width_cm,
            (float) $src->height_cm,
            max(0, (int) $src->ctns),
        );
    }

    protected static function booted(): void
    {
        static::deleting(function (BarangProduct $product): void {
            foreach ([
                $product->photo_path,
                $product->length_photo_path,
                $product->width_photo_path,
                $product->height_photo_path,
            ] as $path) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }
            }
        });

        static::saving(function (BarangProduct $product): void {
            if ($product->parent_id !== null) {
                $product->volume_cbm = 0;
                $product->ctns = 0;
                $product->gross_weight_kg = 0;
                $product->length_cm = 0;
                $product->width_cm = 0;
                $product->height_cm = 0;
                $product->material = null;

                $pcs = max(0, (int) $product->pcs_per_box);
                $unitPrice = RmbFormat::round((float) $product->price_per_unit_rmb);
                $product->price_per_unit_rmb = $unitPrice;

                $parent = $product->relationLoaded('parent') && $product->parent instanceof self
                    ? $product->parent
                    : self::query()->find($product->parent_id);
                $parentCtns = max(0, (int) ($parent->ctns ?? 0));

                $product->total_price_rmb = ($unitPrice > 0 && $pcs > 0 && $parentCtns > 0)
                    ? RmbFormat::lineTotal($unitPrice, $pcs, $parentCtns)
                    : 0;

                return;
            }

            $ctns = max(0, (int) $product->ctns);
            $length = (float) $product->length_cm;
            $width = (float) $product->width_cm;
            $height = (float) $product->height_cm;

            $product->volume_cbm = CbmFormat::truncate(
                CbmVolume::rawLine($length, $width, $height, $ctns),
            );

            $pcs = max(0, (int) $product->pcs_per_box);
            $unitPrice = RmbFormat::round((float) $product->price_per_unit_rmb);
            $product->price_per_unit_rmb = $unitPrice;

            if ($unitPrice > 0 && $pcs > 0 && $ctns > 0) {
                $product->total_price_rmb = RmbFormat::lineTotal($unitPrice, $pcs, $ctns);
            }
        });

        static::saved(function (BarangProduct $product): void {
            if ($product->barang_id) {
                Barang::query()->find($product->barang_id)?->recalculateTotals();
            }
        });

        static::deleted(function (BarangProduct $product): void {
            if ($product->barang_id) {
                Barang::query()->find($product->barang_id)?->recalculateTotals();
            }
        });
    }

    public function lineGrossWeightKg(): float
    {
        return (float) $this->gross_weight_kg * max(1, (int) $this->ctns);
    }

    /** Browser-relative URL (works regardless of APP_URL host/port). */
    public function photoUrl(): ?string
    {
        return \App\Support\PublicStorageUrl::url($this->photo_path);
    }

    public function dimensionPhotoUrl(string $dimension): ?string
    {
        $path = match ($dimension) {
            'length' => $this->length_photo_path,
            'width' => $this->width_photo_path,
            'height' => $this->height_photo_path,
            default => null,
        };

        return \App\Support\PublicStorageUrl::url(self::normalizeStoragePath($path));
    }

    /** Relative path on the public disk, without leading storage/ or public/. */
    public function normalizedPhotoPath(): ?string
    {
        return self::normalizeStoragePath($this->photo_path);
    }

    public static function normalizeStoragePath(?string $rawPath): ?string
    {
        if (! $rawPath) {
            return null;
        }

        $path = str_replace('\\', '/', trim($rawPath));
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, 'public/')) {
            $path = substr($path, 7);
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return $path !== '' ? $path : null;
    }
}
