<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Claim extends Model
{
    public const TYPE_LOSS = 'loss';
    public const TYPE_DAMAGE = 'damage';
    public const TYPE_MISTAKE = 'mistake';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_LOSS,
        self::TYPE_DAMAGE,
        self::TYPE_MISTAKE,
    ];

    protected $fillable = [
        'invoice_id',
        'user_id',
        'claim_type',
        'amount',
        'notes',
        'proof_file',
        'status',
        'review_notes',
        'reviewed_by',
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
        return Attribute::get(fn (): ?string => PublicStorageUrl::url($this->proof_file));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function typeLabel(string $type): string
    {
        $key = 'claim.type_'.$type;

        return __($key) !== $key ? __($key) : ucfirst($type);
    }

    public static function statusLabel(string $status): string
    {
        $key = 'claim.status_'.$status;

        return __($key) !== $key ? __($key) : ucfirst($status);
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => 'badge-status-approved',
            self::STATUS_REJECTED => 'badge-status-rejected',
            default => 'badge-status-pending',
        };
    }
}
