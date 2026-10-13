<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Container extends Model
{
    protected $fillable = [
        'code',
        'container_no',
        'seal_no',
        'type',
        'branch',
        'gudang_china_id',
        'alamat_gudang_china',
        'max_length',
        'max_width',
        'max_height',
        'max_volume',
        'load_date',
        'etd',
        'eta',
        'insurance_value',
        'insurance_rate',
        'insurance_date',
        'gate_pass_date',
        'arrival_date',
        'arrival_date_actual',
        'is_join',
        'route',
        'department_item',
        'length_type',
        'vessel_id',
        'departure_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'arrival_date' => 'date',
            'load_date' => 'date',
            'etd' => 'date',
            'eta' => 'date',
            'insurance_date' => 'date',
            'gate_pass_date' => 'date',
            'arrival_date_actual' => 'date',
            'max_length' => 'decimal:2',
            'max_width' => 'decimal:2',
            'max_height' => 'decimal:2',
            'max_volume' => 'decimal:2',
            'insurance_value' => 'decimal:2',
            'insurance_rate' => 'decimal:2',
            'is_join' => 'boolean',
        ];
    }

    public const TYPES = [
        '20ft' => ['length' => 589, 'width' => 234, 'height' => 238, 'volume' => 33.10],
        '40ft' => ['length' => 1203, 'width' => 234, 'height' => 238, 'volume' => 67.50],
        '40ft HC' => ['length' => 1203, 'width' => 234, 'height' => 269, 'volume' => 76.20],
        '45ft' => ['length' => 1350, 'width' => 234, 'height' => 269, 'volume' => 85.00],
    ];

    public function gudangChina(): BelongsTo
    {
        return $this->belongsTo(MasterGudangChina::class, 'gudang_china_id');
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function barangs(): BelongsToMany
    {
        return $this->belongsToMany(Barang::class, 'container_barangs')
            ->withPivot('loaded_at', 'loaded_by')
            ->withTimestamps();
    }

    public function getUsedVolumeAttribute(): float
    {
        return (float) $this->barangs->sum('total_cbm');
    }

    public function getTotalCtnsAttribute(): int
    {
        return (int) $this->barangs->sum('ctns');
    }

    public function getRemainingVolumeAttribute(): float
    {
        return max(0, (float) $this->max_volume - $this->used_volume);
    }

    public function getCapacityPercentAttribute(): float
    {
        if ($this->max_volume <= 0) {
            return 0;
        }

        return round(($this->used_volume / (float) $this->max_volume) * 100, 1);
    }

    public function getCapacityLevelAttribute(): string
    {
        $pct = $this->capacity_percent;
        if ($pct >= 95) return 'danger';
        if ($pct >= 75) return 'warning';
        return 'safe';
    }

    public function canFit(float $cbm): bool
    {
        return ($this->used_volume + $cbm) <= (float) $this->max_volume;
    }

    public function getReadyInvoicingAttribute(): int
    {
        return $this->barangs->where('price_per_cbm', '>', 0)->count();
    }

    public function getContSealLabelAttribute(): string
    {
        $parts = array_filter([$this->container_no, $this->seal_no]);
        return implode(' / ', $parts) ?: '-';
    }

    public function getCodeCtnsLabelAttribute(): string
    {
        return $this->code . ' / ' . $this->total_ctns;
    }

    public function getBranchLabelAttribute(): string
    {
        if (!$this->branch) return '-';
        $master = \App\Models\MasterBranch::where('code', $this->branch)->first();
        return $master ? $master->name : $this->branch;
    }
}
