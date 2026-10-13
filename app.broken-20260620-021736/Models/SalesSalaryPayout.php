<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesSalaryPayout extends Model
{
    protected $fillable = [
        'sales_id',
        'period',
        'proof_file',
        'proof_uploaded_by',
        'proof_uploaded_at',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'proof_uploaded_at' => 'datetime',
        ];
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proof_uploaded_by');
    }

    public function proofUrl(): ?string
    {
        return PublicStorageUrl::url($this->proof_file);
    }
}
