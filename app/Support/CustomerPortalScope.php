<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customer portal scope:
 * - User induk (punya anak buah): marking cluster sendiri + marking masing-masing anak buah.
 * - Anak buah (terdaftar di user induk): hanya marking yang di-handle (login/session).
 * - Customer biasa (bukan anak buah): cluster relasi marking seperti biasa.
 */
final class CustomerPortalScope
{
    public static function resolveMarking(?User $user = null): ?Marking
    {
        $user ??= auth()->user();
        if ($user === null) {
            return null;
        }

        $sessionId = session('customer_marking_id');
        if (is_numeric($sessionId)) {
            $fromSession = Marking::query()->find((int) $sessionId);
            if ($fromSession !== null) {
                return $fromSession;
            }
        }

        return Marking::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<int> */
    public static function portalUserIds(?User $user = null): array
    {
        $user ??= auth()->user();

        return $user?->portalScopeUserIds() ?? [];
    }

    /** Cluster relasi marking (hub + related) untuk customer biasa. */
    public static function markingRelationClusterForUser(User $user): Collection
    {
        $markings = collect();

        $primary = self::resolveMarking($user);
        if ($primary !== null) {
            $markings = $markings->merge($primary->portalClusterMarkings());
        }

        Marking::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->each(function (Marking $marking) use ($markings): void {
                $markings->push(...$marking->portalClusterMarkings()->all());
            });

        return $markings->unique('id')->values();
    }

    /** Marking tunggal / aktif saja — tanpa gabung relasi marking lain. */
    public static function strictMarkingsForUser(User $user): Collection
    {
        $marking = self::resolveMarking($user);
        if ($marking !== null) {
            return collect([$marking]);
        }

        return Marking::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Marking> */
    public static function clusterMarkings(?User $user = null): Collection
    {
        $user ??= auth()->user();
        if ($user === null) {
            return collect();
        }

        if ($user->isPortalSubordinateOnly()) {
            return self::strictMarkingsForUser($user);
        }

        if (! $user->isPortalParent()) {
            return self::markingRelationClusterForUser($user);
        }

        $markings = collect();

        $primary = self::resolveMarking($user);
        if ($primary !== null) {
            $markings = $markings->merge($primary->portalClusterMarkings());
        }

        Marking::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->each(function (Marking $marking) use ($markings): void {
                $markings->push(...$marking->portalClusterMarkings()->all());
            });

        $user->loadMissing('subordinates');
        foreach ($user->subordinates as $subordinate) {
            foreach (self::strictMarkingsForUser($subordinate) as $marking) {
                $markings->push($marking);
            }
        }

        return $markings->unique('id')->values();
    }

    /** @return list<string> */
    public static function markingCodes(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        $codes = self::clusterMarkings($user)
            ->map(static fn (Marking $m) => trim($m->displayCode()))
            ->filter(static fn (string $code) => $code !== '')
            ->unique()
            ->values()
            ->all();

        if ($codes !== []) {
            return $codes;
        }

        $fromUser = trim((string) ($user->marking_code ?? ''));

        return $fromUser !== '' ? [$fromUser] : [];
    }

    /** @return list<int> */
    public static function markingIds(?User $user = null): array
    {
        return self::clusterMarkings($user)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function applyBarangScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $codes = self::markingCodes($user);
        $ids = self::markingIds($user);
        $expandUserIds = $user?->isPortalParent() ? self::portalUserIds($user) : [];

        if ($codes === [] && $ids === [] && $expandUserIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $inner) use ($codes, $ids, $expandUserIds): void {
            if ($expandUserIds !== []) {
                $inner->orWhereIn('user_id', $expandUserIds);
            }

            if ($ids !== [] && Marking::hasColumnCached('barangs', 'marking_id')) {
                $inner->orWhereIn('marking_id', $ids);
            }

            foreach ($codes as $code) {
                $inner->orWhereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($code)]);
            }
        });
    }

    public static function applyInvoiceScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $codes = self::markingCodes($user);
        $ids = self::markingIds($user);
        $expandUserIds = $user->isPortalParent() ? self::portalUserIds($user) : [(int) $user->id];

        return $query->where(function (Builder $inner) use ($codes, $ids, $expandUserIds): void {
            if ($expandUserIds !== []) {
                $inner->orWhereIn('user_id', $expandUserIds);
            }

            if ($codes !== [] || $ids !== []) {
                $inner->orWhereHas('items.barang', function (Builder $barangQuery) use ($codes, $ids): void {
                    $barangQuery->where(function (Builder $markingMatch) use ($codes, $ids): void {
                        if ($ids !== [] && Marking::hasColumnCached('barangs', 'marking_id')) {
                            $markingMatch->orWhereIn('marking_id', $ids);
                        }

                        foreach ($codes as $code) {
                            $markingMatch->orWhereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($code)]);
                        }
                    });
                });
            }
        });
    }

    public static function barangVisible(Barang $barang, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user?->isPortalParent() && in_array((int) $barang->user_id, self::portalUserIds($user), true)) {
            return true;
        }

        return self::barangMatchesMarkingScope($barang, self::markingCodes($user), self::markingIds($user));
    }

    public static function invoiceVisible(Invoice $invoice, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->isPortalParent() && in_array((int) $invoice->user_id, self::portalUserIds($user), true)) {
            return true;
        }

        $codes = self::markingCodes($user);
        $ids = self::markingIds($user);

        if ($codes === [] && $ids === []) {
            return false;
        }

        return $invoice->items()
            ->whereHas('barang', function (Builder $barangQuery) use ($codes, $ids): void {
                $barangQuery->where(function (Builder $markingMatch) use ($codes, $ids): void {
                    if ($ids !== [] && Marking::hasColumnCached('barangs', 'marking_id')) {
                        $markingMatch->orWhereIn('marking_id', $ids);
                    }

                    foreach ($codes as $code) {
                        $markingMatch->orWhereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($code)]);
                    }
                });
            })
            ->exists();
    }

    /** @param list<string> $codes */
    /** @param list<int> $ids */
    private static function barangMatchesMarkingScope(Barang $barang, array $codes, array $ids): bool
    {
        if ($codes === [] && $ids === []) {
            return false;
        }

        if (
            $ids !== []
            && Marking::hasColumnCached('barangs', 'marking_id')
            && in_array((int) $barang->marking_id, $ids, true)
        ) {
            return true;
        }

        $marking = mb_strtolower(trim((string) ($barang->marking ?? '')));

        foreach ($codes as $code) {
            if ($marking === mb_strtolower($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{portalMarking: ?Marking, portalMarkings: \Illuminate\Support\Collection<int, Marking>}
     */
    public static function portalMarkingViewData(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [
                'portalMarking' => null,
                'portalMarkings' => collect(),
            ];
        }

        $markings = self::clusterMarkings($user);

        return [
            'portalMarking' => $markings->count() === 1 ? $markings->first() : self::resolveMarking($user),
            'portalMarkings' => $markings,
        ];
    }
}
