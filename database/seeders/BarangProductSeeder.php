<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

final class BarangProductSeeder extends Seeder
{
    public function run(): void
    {
        $barang = Barang::query()->where('no_index', 'BRG-2026-0001')->first()
            ?? Barang::query()->orderBy('id')->first();

        if (!$barang) {
            $this->command?->warn('BarangProductSeeder: no barang found, skipping.');

            return;
        }

        $placeholder = $this->ensurePlaceholderImage();

        $samples = [
            [
                'product_name' => '塑料玩具套装',
                'material' => 'ABS塑料',
                'ctns' => 10,
                'pcs_per_box' => 24,
                'gross_weight_kg' => 12.5,
                'length_cm' => 45,
                'width_cm' => 35,
                'height_cm' => 30,
                'price_per_unit_rmb' => 18.5,
            ],
            [
                'product_name' => '不锈钢水杯',
                'material' => '不锈钢304',
                'ctns' => 8,
                'pcs_per_box' => 50,
                'gross_weight_kg' => 15.0,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 28,
                'price_per_unit_rmb' => 22.0,
            ],
            [
                'product_name' => '棉质T恤',
                'material' => '100%棉',
                'ctns' => 6,
                'pcs_per_box' => 100,
                'gross_weight_kg' => 9.8,
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 25,
                'price_per_unit_rmb' => 12.0,
            ],
        ];

        foreach ($samples as $sort => $sample) {
            BarangProduct::query()->updateOrCreate(
                [
                    'barang_id' => $barang->id,
                    'product_name' => $sample['product_name'],
                ],
                array_merge($sample, [
                    'sort_order' => $sort,
                    'photo_path' => $placeholder,
                ])
            );
        }

        $barang->recalculateTotals();

        $this->command?->info("BarangProductSeeder: seeded {$barang->no_index} with " . count($samples) . ' products.');
    }

    private function ensurePlaceholderImage(): string
    {
        $path = 'barang-products/placeholder.png';

        if (!Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put(
                $path,
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
            );
        }

        return $path;
    }
}
