<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'amount',
        'sender_name',
        'proof_file',
        'status',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
    ];

    protected $appends = [
        'proof_url',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    protected function proofUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $path = trim((string) $this->proof_file);
            if ($path === '') {
                return null;
            }

            if (preg_match('#^https?://#i', $path) === 1) {
                return $path;
            }

            $normalized = ltrim(str_replace('\\', '/', $path), '/');
            if (str_starts_with($normalized, 'storage/')) {
                return asset($normalized);
            }

            return asset('storage/'.$normalized);
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
