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
        return self::preview(
            CbmFormat::truncate(max(0, (float) $barang->total_cbm)),
            max(0, (float) $barang->total_gross_weight),
            max(0, (float) $barang->weight_threshold_kg),
            max(0, (float) $barang->price_per_cbm),
            max(0, (float) $barang->weight_price),
            self::resolveMinimumChargeCbm($barang),
        );
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
        float $maxWeightOrCbmCap,
        float $priceCbm,
        float $weightPrice,
        float $minimumChargeCbm = 0,
    ): array {
        if ($maxWeightOrCbmCap > 0 && $cbm > $maxWeightOrCbmCap && $weightPrice > 0 && $priceCbm > 0) {
            $cbmPortion = round($maxWeightOrCbmCap * $priceCbm, 2);
            $excessCbm = $cbm - $maxWeightOrCbmCap;
            $excessKg = $cbm > 0 ? $gw * ($excessCbm / $cbm) : 0.0;
            $weightPortion = round($excessKg * $weightPrice, 2);

            return [
                'method' => self::METHOD_SPLIT,
                'subtotal_idr' => round($cbmPortion + $weightPortion, 2),
                'billable_cbm' => $cbm,
                'cbm_portion_idr' => $cbmPortion,
                'weight_portion_idr' => $weightPortion,
                'excess_cbm' => $excessCbm,
                'excess_kg' => round($excessKg, 2),
                'cap_cbm' => round($maxWeightOrCbmCap, 4),
            ];
        }

        if ($maxWeightOrCbmCap > 0 && $gw > $maxWeightOrCbmCap && $weightPrice > 0 && $priceCbm > 0) {
            $capCbm = min($cbm, $maxWeightOrCbmCap);
            $excessKg = $gw - $maxWeightOrCbmCap;
            $cbmPortion = round($capCbm * $priceCbm, 2);
            $weightPortion = round($excessKg * $weightPrice, 2);

            return [
                'method' => self::METHOD_WEIGHT_SPLIT,
                'subtotal_idr' => round($cbmPortion + $weightPortion, 2),
                'billable_cbm' => $cbm,
                'cbm_portion_idr' => $cbmPortion,
                'weight_portion_idr' => $weightPortion,
                'excess_cbm' => 0.0,
                'excess_kg' => round($excessKg, 2),
                'cap_cbm' => round($capCbm, 4),
                'cap_kg' => $maxWeightOrCbmCap,
            ];
        }

        if ($maxWeightOrCbmCap > 0 && $gw > $maxWeightOrCbmCap && $weightPrice > 0) {
            $weightPortion = round($gw * $weightPrice, 2);

            return [
                'method' => self::METHOD_WEIGHT,
                'subtotal_idr' => $weightPortion,
                'billable_cbm' => $cbm,
                'cbm_portion_idr' => 0.0,
                'weight_portion_idr' => $weightPortion,
                'excess_cbm' => 0.0,
                'excess_kg' => $gw,
            ];
        }

        $billableCbm = $minimumChargeCbm > 0 ? max($cbm, $minimumChargeCbm) : $cbm;
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

        return self::preview(
            max(0, (float) $barang->total_cbm),
            max(0, (float) $barang->total_gross_weight),
            max(0, (float) $barang->weight_threshold_kg),
            $rates['volume_price'],
            $rates['gw_price'],
            self::resolveMinimumChargeCbm($barang),
        )['subtotal_idr'];
    }

    public static function resolveMinimumChargeCbm(Barang $barang): float
    {
        $markingValue = trim((string) ($barang->marking ?? ''));
        if ($markingValue === '') {
            return 0.0;
        }

        $marking = Marking::visibleToUser()
            ->whereBarangMarkingValue($markingValue)
            ->first();

        if ($marking === null) {
            return 0.0;
        }

        return max(0, (float) $marking->resolvedMinimumCharge());
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

    /** Sum of barang_additional_prices (Invoice All / PDF additional rows). */
    public static function additionalPricesTotalIdr(Barang $barang): float
    {
        if ($barang->relationLoaded('additionalPrices')) {
            return (float) $barang->additionalPrices->sum(
                static fn ($row) => max(0, (float) ($row->price ?? 0)),
            );
        }

        if ($barang->exists) {
            $barang->loadMissing('additionalPrices');

            return (float) $barang->additionalPrices->sum(
                static fn ($row) => max(0, (float) ($row->price ?? 0)),
            );
        }

        return 0.0;
    }

    /** extra_charge + all additional prices — matches invoice PDF extra column semantics. */
    public static function extrasTotalIdr(Barang $barang): float
    {
        return round(
            max(0, (float) ($barang->extra_charge ?? 0)) + self::additionalPricesTotalIdr($barang),
            2,
        );
    }

    /** Shipping subtotal + all extras (Invoice All Excel column O). */
    public static function invoiceAllTotalIdr(Barang $barang): float
    {
        return round(self::shippingSubtotalIdr($barang) + self::extrasTotalIdr($barang), 2);
    }
}
