<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Marking;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class NotificationScope
{
    /** Staff who see every notification (not scoped by marking). */
    public static function userCanViewAllNotifications(?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(Marking::SALES_MARKING_SCOPE_ROLES) || $user->hasRole('supervisor')) {
            return false;
        }

        if ($user->can('view all notifications') || $user->can('view all marking')) {
            return true;
        }

        return $user->can('view barang');
    }

    public static function userShouldReceiveBarangNotification(User $user, string $markingValue): bool
    {
        if (! $user->can('view barang') || $user->is_marking_portal || $user->hasRole('customer')) {
            return false;
        }

        if (self::userCanViewAllNotifications($user)) {
            return true;
        }

        $markingValue = trim($markingValue);
        if ($markingValue === '') {
            return false;
        }

        if ($user->hasAnyRole(Marking::SALES_MARKING_SCOPE_ROLES)) {
            return self::markingMatchesSales($markingValue, (int) $user->id);
        }

        if ($user->hasRole('supervisor')) {
            return self::markingMatchesSupervisor($markingValue, (int) $user->id);
        }

        return false;
    }

    public static function userShouldReceiveGeneralShipmentNotification(User $user): bool
    {
        if (! $user->can('view barang') || $user->is_marking_portal || $user->hasRole('customer')) {
            return false;
        }

        return self::userCanViewAllNotifications($user);
    }

    /**
     * @param  Builder<UserNotification>  $query
     * @return Builder<UserNotification>
     */
    public static function applyVisibleScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if ($user === null || self::userCanViewAllNotifications($user)) {
            return $query;
        }

        $markingCodes = self::visibleBarangMarkingValues($user);

        return $query->where(function (Builder $outer) use ($markingCodes): void {
            $outer->where('type', '!=', UserNotification::TYPE_BARANG_CREATED);

            if ($markingCodes !== []) {
                $outer->orWhere(function (Builder $barang) use ($markingCodes): void {
                    $barang->where('type', UserNotification::TYPE_BARANG_CREATED)
                        ->whereIn(
                            DB::raw("JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.marking'))"),
                            $markingCodes,
                        );
                });
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function visibleBarangMarkingValues(?User $user = null): array
    {
        $user ??= auth()->user();

        if ($user === null) {
            return [];
        }

        $codeCol = Marking::codeColumn();
        if ($codeCol === null) {
            return [];
        }

        if ($user->hasAnyRole(Marking::SALES_MARKING_SCOPE_ROLES) || $user->hasRole('supervisor')) {
            return Cache::remember(
                'notif_marking_codes:'.$user->id,
                300,
                static function () use ($user, $codeCol): array {
                    return Marking::visibleToUser($user)
                        ->whereNotNull($codeCol)
                        ->where($codeCol, '!=', '')
                        ->pluck($codeCol)
                        ->map(static fn ($v) => trim((string) $v))
                        ->filter(static fn (string $v) => $v !== '')
                        ->unique()
                        ->values()
                        ->all();
                },
            );
        }

        return [];
    }

    private static function markingMatchesSales(string $markingValue, int $salesId): bool
    {
        $codeCol = Marking::codeColumn();
        if ($codeCol === null) {
            return false;
        }

        return Marking::query()
            ->where($codeCol, $markingValue)
            ->where('sales_id', $salesId)
            ->exists();
    }

    private static function markingMatchesSupervisor(string $markingValue, int $supervisorUserId): bool
    {
        $user = User::query()->find($supervisorUserId);
        if ($user === null) {
            return false;
        }

        $marking = Marking::findForBarangValue($markingValue);
        if ($marking === null) {
            return false;
        }

        return $marking->isVisibleToUser($user);
    }
}
