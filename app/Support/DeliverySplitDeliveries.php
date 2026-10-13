<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\DeliveryTripMarkingSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Optional per-address rows for Surat Jalan PDF only (multi-page download).
 * Stored on marking summaries for re-print; does not split invoices, barang, or finance.
 *
 * Two modes (auto-detected from stored JSON):
 * - coly: legacy — one aggregated line per address with coly count
 * - index: each barang (no_index) assigned to an address group; PDF shows real item rows per page
 */
final class DeliverySplitDeliveries
{
    /**
     * @return list<array{
     *     address: string,
     *     receiver_name: string,
     *     receiver_phone: string,
     *     coly: int,
     *     barang_ids: list<int>,
     *     no_indexes: list<string>,
     * }>
     */
    public static function fromSummaryRow(?DeliveryTripMarkingSummary $row): array
    {
        if ($row === null) {
            return [];
        }

        return self::normalize($row->split_deliveries);
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     */
    public static function usesIndexMode(array $splits): bool
    {
        foreach ($splits as $split) {
            if (($split['barang_ids'] ?? []) !== [] || ($split['no_indexes'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     * @param  Collection<int, Barang>  $barangs
     * @return list<array{
     *     address: string,
     *     receiver_name: string,
     *     receiver_phone: string,
     *     coly: int,
     *     barang_ids: list<int>,
     *     no_indexes: list<string>,
     *     barangs: Collection<int, Barang>,
     * }>
     */
    public static function groupsForIndexPdf(array $splits, Collection $barangs): array
    {
        if ($splits === []) {
            return [];
        }

        $byId = $barangs->keyBy(static fn (Barang $barang): int => (int) $barang->id);
        $byNoIndex = [];
        foreach ($barangs as $barang) {
            $key = mb_strtolower(trim((string) ($barang->no_index ?? '')));
            if ($key !== '') {
                $byNoIndex[$key] = $barang;
            }
        }

        $assignedIds = [];
        $groups = [];

        foreach ($splits as $split) {
            $groupBarangs = collect();
            foreach ($split['barang_ids'] as $barangId) {
                if (isset($assignedIds[$barangId])) {
                    continue;
                }
                $barang = $byId->get($barangId);
                if ($barang instanceof Barang) {
                    $groupBarangs->push($barang);
                    $assignedIds[$barangId] = true;
                }
            }

            foreach ($split['no_indexes'] as $noIndex) {
                $lookup = mb_strtolower(trim($noIndex));
                if ($lookup === '') {
                    continue;
                }
                $barang = $byNoIndex[$lookup] ?? null;
                if (! $barang instanceof Barang) {
                    continue;
                }
                $barangId = (int) $barang->id;
                if (isset($assignedIds[$barangId])) {
                    continue;
                }
                $groupBarangs->push($barang);
                $assignedIds[$barangId] = true;
            }

            if ($groupBarangs->isEmpty() && trim($split['address']) === '') {
                continue;
            }

            $groups[] = [
                'address' => $split['address'],
                'receiver_name' => $split['receiver_name'],
                'receiver_phone' => $split['receiver_phone'],
                'coly' => $split['coly'],
                'barang_ids' => $split['barang_ids'],
                'no_indexes' => $split['no_indexes'],
                'barangs' => $groupBarangs->values(),
            ];
        }

        $unassigned = $barangs->filter(
            static fn (Barang $barang): bool => ! isset($assignedIds[(int) $barang->id]),
        )->values();

        if ($unassigned->isNotEmpty()) {
            $groups[] = [
                'address' => '',
                'receiver_name' => '',
                'receiver_phone' => '',
                'coly' => 0,
                'barang_ids' => [],
                'no_indexes' => [],
                'barangs' => $unassigned,
            ];
        }

        return $groups;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{
     *     address: string,
     *     receiver_name: string,
     *     receiver_phone: string,
     *     coly: int,
     *     barang_ids: list<int>,
     *     no_indexes: list<string>,
     * }>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $address = trim((string) ($item['address'] ?? $item['alamat'] ?? ''));
            $receiverName = trim((string) ($item['receiver_name'] ?? $item['nama_penerima'] ?? ''));
            $receiverPhone = trim((string) ($item['receiver_phone'] ?? $item['telp_penerima'] ?? $item['no_telp_penerima'] ?? ''));
            $coly = self::parseColy($item['coly'] ?? $item['total_coly'] ?? 0);
            $barangIds = self::parseBarangIds($item['barang_ids'] ?? $item['barangIds'] ?? []);
            $noIndexes = self::parseNoIndexes($item['no_indexes'] ?? $item['noIndexes'] ?? []);

            if ($address === '' && $receiverName === '' && $receiverPhone === '' && $coly <= 0
                && $barangIds === [] && $noIndexes === []) {
                continue;
            }

            $out[] = [
                'address' => $address,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'coly' => max(0, $coly),
                'barang_ids' => $barangIds,
                'no_indexes' => $noIndexes,
            ];
        }

        return $out;
    }

    /**
     * Normal save (no split_deliveries in request) must not touch the column.
     */
    public static function requestIncludesSplits(Request $request): bool
    {
        return $request->has('split_deliveries');
    }

    /**
     * @return list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>
     */
    public static function fromRequest(Request $request): array
    {
        if (! self::requestIncludesSplits($request)) {
            return [];
        }

        return self::normalize($request->input('split_deliveries'));
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     * @param  Collection<int, Barang>  $barangs
     */
    public static function assertValidForSave(array $splits, ?string $totalColy, Collection $barangs): void
    {
        if ($splits === []) {
            return;
        }

        if (self::usesIndexMode($splits)) {
            self::assertIndexAssignments($splits, $barangs);

            return;
        }

        self::assertWithinTotalColy($splits, $totalColy);
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     */
    public static function assertWithinTotalColy(array $splits, ?string $totalColy): void
    {
        if ($splits === []) {
            return;
        }

        $maxTotal = self::parseColy($totalColy ?? '');
        if ($maxTotal <= 0) {
            throw ValidationException::withMessages([
                'total_coly' => [__('delivery_trip.split_total_coly_required')],
            ]);
        }

        $sum = 0;
        foreach ($splits as $split) {
            $coly = (int) ($split['coly'] ?? 0);
            if ($coly <= 0) {
                throw ValidationException::withMessages([
                    'split_deliveries' => [__('delivery_trip.split_coly_positive')],
                ]);
            }
            $sum += $coly;
        }

        if ($sum > $maxTotal) {
            throw ValidationException::withMessages([
                'split_deliveries' => [__('delivery_trip.split_coly_exceeds_total', [
                    'sum' => $sum,
                    'total' => $maxTotal,
                ])],
            ]);
        }
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     * @param  Collection<int, Barang>  $barangs
     */
    private static function assertIndexAssignments(array $splits, Collection $barangs): void
    {
        if ($barangs->isEmpty()) {
            throw ValidationException::withMessages([
                'split_deliveries' => [__('delivery_trip.split_index_no_shipments')],
            ]);
        }

        $addressGroups = 0;
        foreach ($splits as $split) {
            if (trim($split['address']) !== '') {
                $addressGroups++;
            }
        }

        if ($addressGroups < 2) {
            throw ValidationException::withMessages([
                'split_deliveries' => [__('delivery_trip.split_index_min_addresses')],
            ]);
        }

        $assigned = 0;
        $barangIds = $barangs->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $idSet = array_fill_keys($barangIds, true);

        foreach ($splits as $split) {
            foreach ($split['barang_ids'] as $barangId) {
                if (isset($idSet[$barangId])) {
                    $assigned++;
                }
            }
        }

        if ($assigned < 2) {
            throw ValidationException::withMessages([
                'split_deliveries' => [__('delivery_trip.split_index_min_assignments')],
            ]);
        }
    }

    public static function parseColy(mixed $value): int
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return 0;
        }

        $normalized = preg_replace('/[^\d]/', '', $normalized) ?? '';

        return (int) $normalized;
    }

    /**
     * @return list<int>
     */
    private static function parseBarangIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private static function parseNoIndexes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            $index = trim((string) $value);
            if ($index !== '') {
                $out[] = $index;
            }
        }

        return $out;
    }
}
