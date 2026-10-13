<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BarangEditRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'barang_id',
        'submitted_by',
        'reviewed_by',
        'status',
        'current_snapshot',
        'proposed_payload',
        'staged_files',
        'rejection_note',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_snapshot' => 'array',
            'proposed_payload' => 'array',
            'staged_files' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Pending di atas, lalu terbaru berdasarkan submitted_at. */
    public function scopeOrderForReviewQueue(Builder $query): Builder
    {
        return $query
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED],
            )
            ->latest('submitted_at');
    }

    /** @param Builder<self> $query */
    public function scopeVisibleToUser(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if (! $user->can('approve barang')) {
            $query->where('submitted_by', $user->id);

            if (Marking::userHasTeamMarkingScope($user)) {
                $query->whereHas(
                    'barang',
                    static fn (Builder $barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
                );
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        $this->loadMissing('barang', 'submitter');

        return [
            'barang_edit_request_id' => $this->id,
            'no_index' => $this->barang?->no_index,
            'marking' => $this->barang?->marking,
            'submitted_by' => $this->submitter?->name,
        ];
    }
}
