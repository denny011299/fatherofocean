<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyBarangPayout extends Model
{
    protected $fillable = [
        'barang_id',
        'proof_file',
        'payout_date',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payout_date' => 'date',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
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
