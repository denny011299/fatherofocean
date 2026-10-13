<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;

/** Harga modal (cost) per pengiriman — prioritas field barang, fallback pengaturan perusahaan. */
final class BarangCostPricing
{
    /** @return array{volume_price: float, gw_price: float} */
    public static function modalRatesForBarang(Barang $barang): array
    {
        $costCbm = $barang->cost_price_per_cbm;
        $costGw = $barang->cost_weight_price;

        if ($costCbm !== null && (float) $costCbm > 0) {
            return [
                'volume_price' => max(0, (float) $costCbm),
                'gw_price' => max(0, (float) ($costGw ?? 0)),
            ];
        }

        if ($costGw !== null && (float) $costGw > 0) {
            return [
                'volume_price' => 0.0,
                'gw_price' => max(0, (float) $costGw),
            ];
        }

        return CompanyRevenueSettings::resolveModalRates($barang->cbm_type);
    }
}
