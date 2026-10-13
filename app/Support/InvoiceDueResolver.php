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
    public static function resolveForBarangs(
        Collection|array $barangs,
        ?string $explicitDueDate = null,
        ?Carbon $from = null,
    ): array {
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
            'due_date' => self::dueDateFromDays($dueDays, $from),
            'due_days' => $dueDays,
            'is_immediate' => $dueDays <= 0,
        ];
    }

    public static function resolveForBarang(
        Barang $barang,
        ?string $explicitDueDate = null,
        ?Carbon $from = null,
    ): array {
        return self::resolveForBarangs(collect([$barang]), $explicitDueDate, $from);
    }

    /**
     * Jatuh tempo hanya dihitung setelah ada status bongkar.
     * Sebelum bongkar → due_date null (UI tampil "—", telat 0).
     *
     * @param  Collection<int, Barang>|array<int, Barang>  $barangs
     * @return array{due_date: ?Carbon, due_days: ?int, is_immediate: bool, pending_bongkar: bool}
     */
    public static function resolveForBarangsUntilBongkar(
        Collection|array $barangs,
        ?string $explicitDueDate = null,
        ?Carbon $from = null,
    ): array {
        $barangs = $barangs instanceof Collection ? $barangs : collect($barangs);

        if ($explicitDueDate !== null && $explicitDueDate !== '') {
            $resolved = self::resolveForBarangs($barangs, $explicitDueDate, $from);

            return [
                'due_date' => $resolved['due_date'],
                'due_days' => $resolved['due_days'],
                'is_immediate' => $resolved['is_immediate'],
                'pending_bongkar' => false,
            ];
        }

        $hasBongkar = $barangs->contains(
            static fn (Barang $barang): bool => Barang::isBongkarStatus((string) $barang->status),
        );

        if (! $hasBongkar) {
            return [
                'due_date' => null,
                'due_days' => self::minDueDaysForBarangs($barangs),
                'is_immediate' => false,
                'pending_bongkar' => true,
            ];
        }

        $resolved = self::resolveForBarangs($barangs, null, $from);

        return [
            'due_date' => $resolved['due_date'],
            'due_days' => $resolved['due_days'],
            'is_immediate' => $resolved['is_immediate'],
            'pending_bongkar' => false,
        ];
    }

    public static function dueDateFromDays(int $days, ?Carbon $from = null): Carbon
    {
        $anchor = ($from ?? today())->copy()->startOfDay();

        return $days <= 0
            ? $anchor
            : $anchor->addDays($days);
    }

    public static function statusForResolved(array $resolved, string $defaultStatus): string
    {
        // Tempo 0 hari = jatuh tempo hari bongkar (bisa telat 0/1...), bukan auto-paid.
        return $defaultStatus;
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
        if (array_key_exists('_invoice_due_days', $barang->getAttributes())) {
            return max(0, (int) $barang->getAttribute('_invoice_due_days'));
        }

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

        if (Marking::hasColumnCached('markings', 'invoice_due_days')) {
            return max(0, (int) ($marking->invoice_due_days ?? 0));
        }

        $days = 0;
        if (Marking::hasColumnCached('marking_pricings', 'invoice_due_days')) {
            $fromPricings = (int) ($marking->pricings->max('invoice_due_days') ?? 0);
            if ($fromPricings > 0) {
                $days = $fromPricings;
            }
        }

        return max(0, $days);
    }
}
