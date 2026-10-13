<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Marking extends Model
{
    protected $attributes = [
        'is_active' => true,
        'invoice_due_days' => 0,
        'max_weight_kg' => 0,
    ];

    protected $fillable = [
        'user_id', 'sales_id', 'code', 'description', 'is_active',
        'contact_person', 'email', 'pic', 'priority', 'branch_id',
        'address_1', 'address_2', 'promo', 'province_id', 'city_id',
        'phone_hp', 'fax', 'phone_02', 'wa_name', 'nik', 'nik_name', 'passcode',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($q)
    {
        return $q->where(function ($query) {
            $query->where('is_active', true)->orWhereNull('is_active');
        });
    }

    public function scopeForDropdown($q)
    {
        return $q->active()->orderBy('code');
    }

    public function dropdownLabel(): string
    {
        $name = trim((string) ($this->customer_name ?? $this->customer?->name ?? ''));

        return $name === '' ? $this->code : "{$this->code} — {$name}";
    }

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function relatedMarking(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_marking_id');
    }

    public function sales(): BelongsTo { return $this->belongsTo(User::class, 'sales_id'); }

    public function branch(): BelongsTo { return $this->belongsTo(MasterBranch::class, 'branch_id'); }

    public function province(): BelongsTo { return $this->belongsTo(Province::class); }

    public function city(): BelongsTo { return $this->belongsTo(City::class); }

    public function pricings(): HasMany { return $this->hasMany(MarkingPricing::class); }

    public function barangs(): HasMany { return $this->hasMany(Barang::class, 'marking', 'code'); }
}
