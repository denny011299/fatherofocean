<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BongkarMuatScan extends Model
{
    protected $fillable = [
        'bongkar_muat_session_id',
        'barang_id',
        'no_index',
        'expected_ctns',
        'confirmed_ctns',
        'variance_note',
        'scanned_by',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BongkarMuatSession::class, 'bongkar_muat_session_id');
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function hasVariance(): bool
    {
        return (int) $this->confirmed_ctns !== (int) $this->expected_ctns;
    }
}
