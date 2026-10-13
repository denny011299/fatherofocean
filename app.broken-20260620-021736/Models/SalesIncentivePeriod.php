<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesIncentivePeriod extends Model
{
    protected $fillable = [
        'sales_id',
        'period',
        'cbm_stuffing',
        'cbm_paid',
        'estimasi_amount',
        'cair_amount',
    ];

    protected function casts(): array
    {
        return [
            'cbm_stuffing' => 'float',
            'cbm_paid' => 'float',
            'estimasi_amount' => 'float',
            'cair_amount' => 'float',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }
}
