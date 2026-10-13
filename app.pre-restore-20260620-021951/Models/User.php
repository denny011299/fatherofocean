<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
        'sales_commission_rate_override',
        'address',
        'marking_code',
        'is_marking_portal',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_marking_portal' => 'boolean',
            'sales_commission_rate_override' => 'decimal:2',
        ];
    }

    /** Persentase komisi khusus; null = ikut pengaturan umum (sales/supervisor). */
    public function commissionRateOverride(): ?float
    {
        $value = $this->sales_commission_rate_override;

        return $value === null ? null : (float) $value;
    }

    public function hasCommissionRateOverride(): bool
    {
        return $this->commissionRateOverride() !== null;
    }

    /** Users shown on Manajemen User (excludes auto accounts for marking login). */
    public function scopeListedInUserManagement(Builder $query): Builder
    {
        return $query->where('is_marking_portal', false);
    }

    /** Marketing / Sales assignees on marking (sales + supervisor). */
    public function scopeMarketingStaff(Builder $query): Builder
    {
        return $query->whereHas(
            'roles',
            static fn (Builder $roleQuery) => $roleQuery->whereIn('name', ['sales', 'supervisor']),
        );
    }

    public function isSupervisorOnly(): bool
    {
        return $this->hasRole('supervisor') && ! $this->hasRole('sales');
    }

    public function earnsCapitalGainOnly(): bool
    {
        return $this->hasRole('supervisor');
    }

    public static function subordinatesTableReady(): bool
    {
        return Schema::hasTable('user_subordinates');
    }

    public function markings(): HasMany
    {
        return $this->hasMany(Marking::class);
    }

    public function barangs(): HasMany
    {
        return $this->hasMany(Barang::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /** Users assigned as anak buah (subordinates) for combined portal scope. */
    public function subordinates(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'user_subordinates',
            'parent_user_id',
            'subordinate_user_id',
        )->withTimestamps();
    }

    /** Parent users that include this account as subordinate. */
    public function supervisingUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'user_subordinates',
            'subordinate_user_id',
            'parent_user_id',
        )->withTimestamps();
    }

    /** User induk: punya anak buah → portal gabungan (tracking, invoice, dll.). */
    public function isPortalParent(): bool
    {
        if (! self::subordinatesTableReady()) {
            return false;
        }

        if ($this->relationLoaded('subordinates')) {
            return $this->subordinates->isNotEmpty();
        }

        return $this->subordinates()->exists();
    }

    /** Anak buah (bukan induk): hanya marking sendiri, tanpa gabungan induk/rekan. */
    public function isPortalSubordinateOnly(): bool
    {
        if (! self::subordinatesTableReady()) {
            return false;
        }

        if ($this->isPortalParent()) {
            return false;
        }

        if ($this->relationLoaded('supervisingUsers')) {
            return $this->supervisingUsers->isNotEmpty();
        }

        return $this->supervisingUsers()->exists();
    }

    /**
     * ID user untuk filter barang/invoice.
     * Hanya induk yang menyertakan anak buah; sisanya hanya diri sendiri.
     *
     * @return list<int>
     */
    public function portalScopeUserIds(): array
    {
        $ids = [(int) $this->id];

        if (! self::subordinatesTableReady() || ! $this->isPortalParent()) {
            return $ids;
        }

        if ($this->relationLoaded('subordinates')) {
            foreach ($this->subordinates as $sub) {
                $ids[] = (int) $sub->id;
            }
        } else {
            $ids = array_merge(
                $ids,
                $this->subordinates()->pluck('users.id')->map(static fn ($id) => (int) $id)->all(),
            );
        }

        return array_values(array_unique($ids));
    }
}
