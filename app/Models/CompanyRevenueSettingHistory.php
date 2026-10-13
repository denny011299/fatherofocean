<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyRevenueSettingHistory extends Model
{
    protected $fillable = [
        'setting_key',
        'old_value',
        'new_value',
        'changed_by_user_id',
        'changed_at',
        'notes',
    ];

    protected $casts = [
        'old_value' => 'decimal:2',
        'new_value' => 'decimal:2',
        'changed_at' => 'datetime',
    ];

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
