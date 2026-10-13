<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;

/**
 * Shipping charge rules (aligned with barang form / invoice):
 * 1. billable_cbm = max(actual CBM, marking minimum_charge)
 * 2. berat_maksimal = billable_cbm × weight_threshold_kg (kg per 1 CBM)
 * 3. If gross weight > berat_maksimal → billable_cbm × CBM rate + excess kg × weight_price
 * 4. Else billable_cbm × price_per_cbm only (min charge CBM when applicable)
 */
final class BarangShippingPricing
{
    public const METHOD_CBM = 'cbm';

    public const METHOD_WEIGHT = 'weight';

    public const METHOD_SPLIT = 'split';

    /** Berat melebihi batas: bagian CBM (min cbm,cap)×harga CBM + sisa kg×harga/kg */
    public const METHOD_WEIGHT_SPLIT = 'weight_split';

    /**
     * @return array{
     *     method: string,
     *     subtotal_idr: float,
     *     billable_cbm: float,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     * }
     */
    public static function calculate(Barang $barang): array
    {
        $rates = self::resolveSellRates($barang);
        $threshold = self::normalizeThresholdPerCbmKg($rates['weight_threshold_kg']);

        return self::preview(
            CbmFormat::truncate(max(0, (float) $barang->total_cbm)),
            max(0, (float) $barang->total_gross_weight),
            $threshold,
            $rates['price_per_cbm'],
            $rates['weight_price'],
            self::resolveMinimumChargeCbm($barang),
        );
    }

    /**
     * Jual rates: kolom barang dulu, lalu pricing marking (sama seperti form barang).
     * Max kg/CBM (threshold overweight): snapshot barang dulu, lalu Marking.max_weight_kg.
     *
     * @return array{price_per_cbm: float, weight_price: float, weight_threshold_kg: float}
     */
    public static function resolveSellRates(Barang $barang): array
    {
        $priceCbm = max(0, (float) $barang->price_per_cbm);
        $weightPrice = max(0, (float) $barang->weight_price);

        $pricingRow = MarkingPricingResolver::forBarang($barang);
        if ($pricingRow !== null) {
            if ($priceCbm <= 0) {
                $priceCbm = max(0, (float) ($pricingRow->volume_price ?? 0));
            }
            if ($weightPrice <= 0) {
                $weightPrice = max(0, (float) ($pricingRow->gw_price ?? 0));
            }
        }

        // Harga jual kg sering kosong di snapshot barang — cari GW Price di marking.
        if ($weightPrice <= 0) {
            $marking = self::findMarkingForPricing($barang);
            if ($marking !== null) {
                $marking->loadMissing('pricings');
                foreach ($marking->pricings as $row) {
                    $gw = max(0, (float) ($row->gw_price ?? 0));
                    if ($gw > 0) {
                        $weightPrice = $gw;
                        break;
                    }
                }
            }
        }

        return [
            'price_per_cbm' => $priceCbm,
            'weight_price' => $weightPrice,
            'weight_threshold_kg' => self::resolveWeightThresholdKg($barang),
        ];
    }

    /**
     * Batas overweight (kg per 1 CBM): prioritas snapshot barang, lalu Marking.max_weight_kg.
     */
    public static function resolveWeightThresholdKg(Barang $barang): float
    {
        $fromBarang = max(0, (float) $barang->weight_threshold_kg);
        if ($fromBarang > 0) {
            return $fromBarang;
        }

        $marking = self::findMarkingForPricing($barang);
        if ($marking !== null) {
            return max(0, (float) ($marking->max_weight_kg ?? 0));
        }

        return 0.0;
    }

    /**
     * @return array{
     *     method: string,
     *     subtotal_idr: float,
     *     billable_cbm: float,
     *     cbm_portion_idr: float,
     *     weight_portion_idr: float,
     *     excess_cbm: float,
     *     excess_kg: float,
     * }
     */
    public static function preview(
        float $cbm,
        float $gw,
        float $maxWeightPerCbmKg,
        float $priceCbm,
        float $weightPrice,
        float $minimumChargeCbm = 0,
    ): array {
        $cbm = CbmFormat::truncate($cbm);
        $maxWeightPerCbmKg = self::normalizeThresholdPerCbmKg($maxWeightPerCbmKg);
        $billableCbm = $minimumChargeCbm > 0 ? max($cbm, $minimumChargeCbm) : $cbm;

        // Berat maksimal scales with billable CBM (includes min charge), not raw volume.
        $maxTotalWeightKg = $maxWeightPerCbmKg > 0 ? ($billableCbm * $maxWeightPerCbmKg) : 0.0;

        if ($maxTotalWeightKg > 0 && $gw > $maxTotalWeightKg && $weightPrice > 0 && $priceCbm > 0) {
            $excessKg = $gw - $maxTotalWeightKg;
            $cbmPortion = round($billableCbm * $priceCbm, 2);
            $weightPortion = round($excessKg * $weightPrice, 2);

            return [
                'method' => self::METHOD_WEIGHT_SPLIT,
                'subtotal_idr' => round($cbmPortion + $weightPortion, 2),
                'billable_cbm' => $billableCbm,
                'cbm_portion_idr' => $cbmPortion,
                'weight_portion_idr' => $weightPortion,
                'excess_cbm' => 0.0,
                'excess_kg' => round($excessKg, 2),
                'cap_kg_per_cbm' => round($maxWeightPerCbmKg, 4),
                'cap_kg' => round($maxTotalWeightKg, 4),
            ];
        }

        if ($maxTotalWeightKg > 0 && $gw > $maxTotalWeightKg && $weightPrice > 0) {
            $weightPortion = round($gw * $weightPrice, 2);

            return [
                'method' => self::METHOD_WEIGHT,
                'subtotal_idr' => $weightPortion,
                'billable_cbm' => $billableCbm,
                'cbm_portion_idr' => 0.0,
                'weight_portion_idr' => $weightPortion,
                'excess_cbm' => 0.0,
                'excess_kg' => $gw,
            ];
        }
        $cbmPortion = round($billableCbm * $priceCbm, 2);

        return [
            'method' => self::METHOD_CBM,
            'subtotal_idr' => $cbmPortion,
            'billable_cbm' => $billableCbm,
            'cbm_portion_idr' => $cbmPortion,
            'weight_portion_idr' => 0.0,
            'excess_cbm' => 0.0,
            'excess_kg' => 0.0,
        ];
    }

    public static function shippingSubtotalIdr(Barang $barang): float
    {
        return self::calculate($barang)['subtotal_idr'];
    }

    /** Harga modal dari field barang (prioritas) atau marking pricing; aturan CBM/berat sama seperti tagihan jual. */
    public static function costSubtotalIdr(Barang $barang): float
    {
        $rates = BarangCostPricing::modalRatesForBarang($barang);
        $threshold = self::normalizeThresholdPerCbmKg(self::resolveWeightThresholdKg($barang));

        return self::preview(
            CbmFormat::truncate(max(0, (float) $barang->total_cbm)),
            max(0, (float) $barang->total_gross_weight),
            $threshold,
            $rates['volume_price'],
            $rates['gw_price'],
            self::resolveMinimumChargeCbm($barang),
        )['subtotal_idr'];
    }

    public static function normalizeThresholdPerCbmKg(float $value): float
    {
        $value = max(0, $value);
        $base = floor($value);
        $fraction = $value - $base;

        // Custom rounding rule: 1-5 down, 6-9 up.
        return (float) ($fraction > 0.5 ? ((int) $base + 1) : (int) $base);
    }

    public static function resolveMinimumChargeCbm(Barang $barang): float
    {
        // Override runtime (unit test / tooling) — bukan kolom DB.
        if (array_key_exists('_minimum_charge_cbm', $barang->getAttributes())) {
            return max(0, (float) $barang->getAttribute('_minimum_charge_cbm'));
        }

        if (
            Marking::hasColumnCached('barangs', 'minimum_charge_cbm')
            && array_key_exists('minimum_charge_cbm', $barang->getAttributes())
            && $barang->getAttribute('minimum_charge_cbm') !== null
        ) {
            return max(0, (float) $barang->getAttribute('minimum_charge_cbm'));
        }

        $marking = self::findMarkingForPricing($barang);
        if ($marking === null) {
            return 0.0;
        }

        return max(0, (float) $marking->resolvedMinimumCharge());
    }

    /** Lookup marking master untuk tarif (tanpa filter visibleToUser — hitung harus ikut master). */
    private static function findMarkingForPricing(Barang $barang): ?Marking
    {
        $markingValue = trim((string) ($barang->marking ?? ''));
        if ($markingValue === '') {
            return null;
        }

        return Marking::query()
            ->whereBarangMarkingValue($markingValue)
            ->first();
    }

    public static function syncInvoiceItemLineTotal(
        float $cbm,
        float $pricePerCbm,
        float $insurance,
        float $extra,
        ?Barang $barang = null,
    ): float {
        $shipping = $barang !== null
            ? self::shippingSubtotalIdr($barang)
            : round(max(0, CbmFormat::truncate($cbm)) * max(0, $pricePerCbm), 2);

        return round($shipping + max(0, $insurance) + max(0, $extra), 2);
    }
}
