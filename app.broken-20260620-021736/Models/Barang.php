<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Barang extends Model
{
    protected $fillable = [
        'no_index', 'no_po', 'marking', 'supplier_id', 'supplier_chinese',
        'no_resi', 'user_id', 'customer_name', 'warehouse_origin', 'tanggal_masuk',
        'ctns', 'pcs', 'price',
        'material', 'form', 'material_desc',
        'insurance_value', 'insurance_rate', 'insurance_nominal_standar', 'insurance_nominal_custom',
        'total_cbm', 'total_gross_weight', 'cbm_type', 'rate', 'weight',
        'destination', 'other', 'notes', 'notes_id', 'keterangan',
        'status', 'price_per_cbm', 'extra_charge',
        'is_preload', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_masuk' => 'date',
            'insurance_value' => 'decimal:2',
            'insurance_rate' => 'decimal:2',
            'total_cbm' => 'decimal:4',
            'total_gross_weight' => 'decimal:2',
            'price_per_cbm' => 'decimal:2',
            'extra_charge' => 'decimal:2',
            'weight_price' => 'decimal:2',
            'cbm_threshold' => 'decimal:2',
            'price' => 'decimal:2',
            'rate' => 'decimal:2',
            'weight' => 'decimal:2',
            'insurance_nominal_standar' => 'decimal:2',
            'insurance_nominal_custom' => 'decimal:2',
            'is_preload' => 'boolean',
            'no_resi' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function packing(): BelongsTo
    {
        return $this->belongsTo(MasterPacking::class, 'packing_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(BarangDimension::class);
    }

    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Container::class, 'container_barangs')
            ->withPivot('loaded_at', 'loaded_by')
            ->withTimestamps();
    }

    public function trackingStatuses(): HasMany
    {
        return $this->hasMany(TrackingStatus::class)->orderByDesc('created_at');
    }

    public function latestTracking(): HasOne
    {
        return $this->hasOne(TrackingStatus::class)->latestOfMany();
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BarangTask::class)->orderBy('sort_order');
    }

    public function recalculateTotals(): void
    {
        $dims = $this->dimensions;
        $this->total_cbm = $dims->sum('volume_cbm');
        $this->total_gross_weight = $dims->sum('gross_weight');
        $this->save();
    }

    public function getStatusBadgeAttribute(): string
    {
        return 'badge-status-' . $this->status;
    }

    public function getContainerCodeAttribute(): ?string
    {
        return $this->containers->first()?->code;
    }
}
