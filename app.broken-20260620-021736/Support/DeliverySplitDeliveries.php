<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DeliveryTripMarkingSummary;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeliverySplitDeliveries
{
    /**
     * @return list<array{address: string, receiver_name: string, receiver_phone: string, coly: int}>
     */
    public static function fromSummaryRow(?DeliveryTripMarkingSummary $row): array
    {
        if ($row === null) {
            return [];
        }

        return self::normalize($row->split_deliveries);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{address: string, receiver_name: string, receiver_phone: string, coly: int}>
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

            if ($address === '' && $receiverName === '' && $receiverPhone === '' && $coly <= 0) {
                continue;
            }

            $out[] = [
                'address' => $address,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'coly' => max(0, $coly),
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
     * @return list<array{address: string, receiver_name: string, receiver_phone: string, coly: int}>
     */
    public static function fromRequest(Request $request): array
    {
        if (! self::requestIncludesSplits($request)) {
            return [];
        }

        return self::normalize($request->input('split_deliveries'));
    }

    /**
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int}>  $splits
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

    public static function parseColy(mixed $value): int
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return 0;
        }

        $normalized = preg_replace('/[^\d]/', '', $normalized) ?? '';

        return (int) $normalized;
    }
}
