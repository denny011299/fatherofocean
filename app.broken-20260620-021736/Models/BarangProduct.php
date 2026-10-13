<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CbmFormat;
use App\Support\RmbFormat;
use App\Support\PublicStorageUrl;
use App\Support\RmbFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'net_weight_kg',
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
            'net_weight_kg' => 'decimal:2',
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
                $pcs = max(0, (int) $product->pcs_per_box);
                $unitPrice = RmbFormat::round((float) $product->price_per_unit_rmb);
                $product->price_per_unit_rmb = $unitPrice;
                if ($unitPrice > 0 && $pcs > 0) {
                    $product->total_price_rmb = RmbFormat::lineTotal($unitPrice, $pcs);
                }

                return;
            }

            $ctns = max(0, (int) $product->ctns);
            $length = (float) $product->length_cm;
            $width = (float) $product->width_cm;
            $height = (float) $product->height_cm;

            $product->volume_cbm = CbmFormat::truncate(
                ($length * $width * $height * $ctns) / 1_000_000,
            );

            $pcs = max(0, (int) $product->pcs_per_box);
            $unitPrice = RmbFormat::round((float) $product->price_per_unit_rmb);
            $product->price_per_unit_rmb = $unitPrice;

            if ($unitPrice > 0 && $pcs > 0 && $ctns > 0) {
                $product->total_price_rmb = RmbFormat::lineTotal($unitPrice, $pcs, $ctns);
            }
        });
    }

    public function lineGrossWeightKg(): float
    {
        return (float) $this->gross_weight_kg * max(1, (int) $this->ctns);
    }

    public function photoUrl(): ?string
    {
        return PublicStorageUrl::url(self::normalizeStoragePath($this->photo_path));
    }

    public static function normalizeStoragePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return str_replace('\\', '/', ltrim($path, '/'));
    }
}
