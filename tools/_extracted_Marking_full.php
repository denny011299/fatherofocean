<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Marking extends Model
{
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

    public function scopeActive($q) { return $q->where('is_active', true); }

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function sales(): BelongsTo { return $this->belongsTo(User::class, 'sales_id'); }

    public function branch(): BelongsTo { return $this->belongsTo(MasterBranch::class, 'branch_id'); }

    public function province(): BelongsTo { return $this->belongsTo(Province::class); }

    public function city(): BelongsTo { return $this->belongsTo(City::class); }

    public function pricings(): HasMany { return $this->hasMany(MarkingPricing::class); }

    public function barangs(): HasMany { return $this->hasMany(Barang::class, 'marking', 'code'); }
}
