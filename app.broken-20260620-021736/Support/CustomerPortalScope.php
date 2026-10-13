<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Scopes barang & invoice queries for customer portal (one marking per login). */
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

    /** @return list<string> */
    public static function markingCodes(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        $marking = self::resolveMarking($user);
        if ($marking !== null) {
            $code = trim($marking->displayCode());

            return $code !== '' ? [$code] : [];
        }

        $fromUser = trim((string) ($user->marking_code ?? ''));

        return $fromUser !== '' ? [$fromUser] : [];
    }

    public static function applyBarangScope(Builder $query, ?User $user = null): Builder
    {
        $codes = self::markingCodes($user);
        if ($codes === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $inner) use ($codes): void {
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

        return $query->where(function (Builder $inner) use ($user, $codes): void {
            $inner->where('user_id', $user->id);

            if ($codes !== []) {
                $inner->orWhereHas('items.barang', function (Builder $barangQuery) use ($codes): void {
                    $barangQuery->where(function (Builder $markingMatch) use ($codes): void {
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
        $codes = self::markingCodes($user);
        if ($codes === []) {
            return false;
        }

        $marking = mb_strtolower(trim((string) ($barang->marking ?? '')));

        foreach ($codes as $code) {
            if ($marking === mb_strtolower($code)) {
                return true;
            }
        }

        return false;
    }

    public static function invoiceVisible(Invoice $invoice, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user === null) {
            return false;
        }

        if ((int) $invoice->user_id === (int) $user->id) {
            return true;
        }

        $codes = self::markingCodes($user);
        if ($codes === []) {
            return false;
        }

        return $invoice->items()
            ->whereHas('barang', function (Builder $barangQuery) use ($codes): void {
                $barangQuery->where(function (Builder $markingMatch) use ($codes): void {
                    foreach ($codes as $code) {
                        $markingMatch->orWhereRaw('LOWER(TRIM(marking)) = ?', [mb_strtolower($code)]);
                    }
                });
            })
            ->exists();
    }
}
