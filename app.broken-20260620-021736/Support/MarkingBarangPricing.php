<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\MarkingPricing;
use Illuminate\Http\Request;

/**
 * Snapshots marking shipping prices onto barang rows (mirrors DailyRate for RMB rate).
 *
 * Wire in BarangController store/update before persisting:
 *   MarkingBarangPricing::applyToRequest($request);
 *   MarkingBarangPricing::applyToRequest($request, $barang);
 */
final class MarkingBarangPricing
{
    public static function pickPricingRow(Marking $marking, string $cbmType): ?MarkingPricing
    {
        $marking->loadMissing('pricings.pricingItem');

        return MarkingPricingResolver::pickRow($marking->pricings, $cbmType);
    }

    /**
     * @return array{price_per_cbm: float, weight_price: float, weight_threshold_kg: float}|null
     */
    public static function snapshotFromMarking(Marking $marking, string $cbmType): ?array
    {
        $row = self::pickPricingRow($marking, $cbmType);
        if ($row === null) {
            return null;
        }

        return [
            'price_per_cbm' => (float) $row->volume_price,
            'weight_price' => (float) $row->gw_price,
            'weight_threshold_kg' => (float) ($marking->max_weight_kg ?? 0),
        ];
    }

    public static function applyToRequest(Request $request, ?Barang $existing = null): void
    {
        if ($existing !== null && ! self::markingPricingInputsChanged($request, $existing)) {
            $request->merge([
                'price_per_cbm' => $existing->price_per_cbm,
                'weight_price' => $existing->weight_price,
                'weight_threshold_kg' => $existing->weight_threshold_kg,
            ]);

            return;
        }

        $markingCode = trim((string) $request->input('marking', ''));
        if ($markingCode === '') {
            return;
        }

        $marking = Marking::query()->where('code', $markingCode)->first();
        if ($marking === null) {
            return;
        }

        $snapshot = self::snapshotFromMarking($marking, (string) $request->input('cbm_type', ''));
        if ($snapshot === null) {
            return;
        }

        $request->merge($snapshot);
    }

    private static function markingPricingInputsChanged(Request $request, Barang $existing): bool
    {
        $newMarking = trim((string) $request->input('marking', ''));
        $oldMarking = trim((string) ($existing->marking ?? ''));
        $newCbmType = trim((string) $request->input('cbm_type', ''));
        $oldCbmType = trim((string) ($existing->cbm_type ?? ''));

        return $newMarking !== $oldMarking || $newCbmType !== $oldCbmType;
    }
}
