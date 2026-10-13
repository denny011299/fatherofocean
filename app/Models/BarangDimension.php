<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BarangDimension extends Model
{
    protected $fillable = [
        'barang_id', 'item_name',
        'panjang', 'lebar', 'tinggi', 'ctn_count',
        'count_by',
        'volume_cbm', 'gw_per_ctn', 'gross_weight',
    ];

    protected function casts(): array
    {
        return [
            'panjang' => 'decimal:2',
            'lebar' => 'decimal:2',
            'tinggi' => 'decimal:2',
            'volume_cbm' => 'decimal:6',
            'gross_weight' => 'decimal:2',
            'gw_per_ctn' => 'decimal:2',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    protected static function booted(): void
    {
        static::saving(function (BarangDimension $dim) {
            $dim->volume_cbm = \App\Support\CbmFormat::truncate(
                \App\Support\CbmVolume::rawLine(
                    (float) $dim->panjang,
                    (float) $dim->lebar,
                    (float) $dim->tinggi,
                    (int) $dim->ctn_count,
                ),
            );
            if ($dim->gw_per_ctn > 0 && $dim->ctn_count > 0) {
                $dim->gross_weight = $dim->gw_per_ctn * $dim->ctn_count;
            }
        });
    }
}
