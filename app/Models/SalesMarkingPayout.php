<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesMarkingPayout extends Model
{
    protected $fillable = [
        'sales_id',
        'marking',
        'amount',
        'payout_date',
        'proof_file',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payout_date' => 'date',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function proofUrl(): ?string
    {
        return PublicStorageUrl::url($this->proof_file);
    }
}
