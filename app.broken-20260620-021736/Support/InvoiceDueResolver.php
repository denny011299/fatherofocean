<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class InvoiceDueResolver
{
    /** Default credit term when marking has no setting. */
    public const DEFAULT_DUE_DAYS = 7;

    /**
     * @param  Collection<int, Barang>|array<int, Barang>  $barangs
     */
    public static function resolveForBarangs(Collection|array $barangs, ?string $explicitDueDate = null): array
    {
        $barangs = $barangs instanceof Collection ? $barangs : collect($barangs);

        if ($explicitDueDate !== null && $explicitDueDate !== '') {
            return [
                'due_date' => Carbon::parse($explicitDueDate)->startOfDay(),
                'due_days' => null,
                'is_immediate' => false,
            ];
        }

        $dueDays = self::minDueDaysForBarangs($barangs);

        return [
            'due_date' => self::dueDateFromDays($dueDays),
            'due_days' => $dueDays,
            'is_immediate' => $dueDays <= 0,
        ];
    }

    public static function resolveForBarang(Barang $barang, ?string $explicitDueDate = null): array
    {
        return self::resolveForBarangs(collect([$barang]), $explicitDueDate);
    }

    public static function dueDateFromDays(int $days): Carbon
    {
        return $days <= 0
            ? today()->startOfDay()
            : today()->addDays($days)->startOfDay();
    }

    public static function statusForResolved(array $resolved, string $defaultStatus): string
    {
        return ($resolved['is_immediate'] ?? false) ? 'paid' : $defaultStatus;
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    public static function minDueDaysForBarangs(Collection $barangs): int
    {
        if ($barangs->isEmpty()) {
            return self::DEFAULT_DUE_DAYS;
        }

        return (int) $barangs
            ->map(static fn (Barang $barang): int => self::dueDaysForBarang($barang))
            ->min();
    }

    public static function dueDaysForBarang(Barang $barang): int
    {
        $markingValue = trim((string) ($barang->marking ?? ''));

        if ($markingValue === '') {
            return self::DEFAULT_DUE_DAYS;
        }

        $marking = Marking::query()
            ->whereBarangMarkingValue($markingValue)
            ->active()
            ->with('pricings:id,marking_id,invoice_due_days')
            ->first(['id', 'invoice_due_days']);

        return self::dueDaysForMarking($marking);
    }

    public static function dueDaysForMarking(?Marking $marking): int
    {
        if ($marking === null) {
            return self::DEFAULT_DUE_DAYS;
        }

        $days = (int) ($marking->invoice_due_days ?? 0);

        if ($days <= 0 && Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
            $fromPricings = (int) ($marking->pricings->max('invoice_due_days') ?? 0);
            if ($fromPricings > 0) {
                $days = $fromPricings;
            }
        }

        return max(0, $days);
    }
}
