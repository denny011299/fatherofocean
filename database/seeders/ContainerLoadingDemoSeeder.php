<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Models\Marking;
use App\Models\MasterContainerType;
use App\Models\MasterGudangChina;
use App\Models\MasterRoute;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Port;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

final class ContainerLoadingDemoSeeder extends Seeder
{
    public const CONTAINER_CODE = 'DEMO-PL-TEST';

    public function run(): void
    {
        $admin = User::query()->where('username', 'admin')->first();
        $customer = User::query()->where('username', 'customer')->first();

        if ($admin === null || $customer === null) {
            $this->command?->warn('ContainerLoadingDemoSeeder: run DatabaseSeeder first (admin + customer users).');

            return;
        }

        $gudang = MasterGudangChina::query()->firstOrCreate(
            ['name' => 'Demo Warehouse Guangzhou'],
            [
                'address' => 'No. 88 Demo Logistics Park, Baiyun District, Guangzhou, China',
                'is_active' => true,
            ],
        );

        $marking = Marking::query()->firstOrCreate(
            ['code' => 'DEMO-PL-MARK'],
            [
                'user_id' => $customer->id,
                'customer_name' => 'PT Demo Packing List Indonesia',
                'description' => 'Marking khusus uji export Packing List Excel',
                'address_1' => 'Jl. Raya Demo No. 123, Surabaya',
                'address_2' => 'Jawa Timur 60111, Indonesia',
                'is_active' => true,
            ],
        );

        $marking->fill([
            'customer_name' => 'PT Demo Packing List Indonesia',
            'address_1' => 'Jl. Raya Demo No. 123, Surabaya',
            'address_2' => 'Jawa Timur 60111, Indonesia',
        ])->save();

        $route = MasterRoute::query()
            ->where('name', 'CHINA - SURABAYA (FCL)')
            ->first();

        $containerType = MasterContainerType::query()->where('code', '40ft HC')->first();
        $port = Port::query()->where('code', 'IDSUB')->first()
            ?? Port::query()->orderBy('id')->first();
        $supplier = Supplier::query()->first();

        $loadDate = now()->subDays(3);
        $etd = now()->addDays(30);
        $eta = now()->addDays(45);

        $container = Container::query()->firstOrCreate(
            ['partai' => self::CONTAINER_CODE],
            [
                'container_no' => 'DEMOU1234567',
                'no_bl' => 'DEMO-BL-001',
                'type' => $containerType?->code ?? '40ft HC',
                'branch' => 'SBY',
                'gudang_china_id' => $gudang->id,
                'marking_id' => $marking->id,
                'alamat_gudang_china' => $gudang->address,
                'max_length' => $containerType?->length ?? 1203,
                'max_width' => $containerType?->width ?? 234,
                'max_height' => $containerType?->height ?? 269,
                'max_volume' => $containerType?->volume ?? 76.20,
                'load_date' => $loadDate,
                'etd' => $etd,
                'eta' => $eta,
                'departure_date' => $etd,
                'route' => $route?->name ?? 'CHINA - SURABAYA (FCL)',
                'department_item' => 'BRG UMUM',
                'length_type' => '40ft HC',
                'port_id' => $port?->id,
                'notes' => 'Demo container untuk uji packing list.',
                'status' => 'open',
                'created_by' => $admin->id,
            ],
        );

        $placeholder = $this->ensurePlaceholderImage();

        $barangDefinitions = [
            [
                'no_index' => 'DEMO-PL-BRG-01',
                'no_po' => 'PO-DEMO-PL-01',
                'products' => [
                    [
                        'product_name' => 'LED Ceiling Light 24W',
                        'material' => 'Aluminum + PC',
                        'ctns' => 12,
                        'pcs_per_box' => 20,
                        'gross_weight_kg' => 14.5,
                        'length_cm' => 48,
                        'width_cm' => 38,
                        'height_cm' => 32,
                        'price_per_unit_rmb' => 28.5,
                    ],
                    [
                        'product_name' => 'LED Driver 24W',
                        'material' => 'Electronic components',
                        'ctns' => 6,
                        'pcs_per_box' => 50,
                        'gross_weight_kg' => 11.0,
                        'length_cm' => 42,
                        'width_cm' => 34,
                        'height_cm' => 28,
                        'price_per_unit_rmb' => 8.5,
                    ],
                ],
            ],
            [
                'no_index' => 'DEMO-PL-BRG-02',
                'no_po' => 'PO-DEMO-PL-02',
                'products' => [
                    [
                        'product_name' => 'Stainless Kitchen Pot Set',
                        'material' => 'Stainless steel 304',
                        'ctns' => 8,
                        'pcs_per_box' => 10,
                        'gross_weight_kg' => 18.0,
                        'length_cm' => 55,
                        'width_cm' => 45,
                        'height_cm' => 35,
                        'price_per_unit_rmb' => 65.0,
                    ],
                ],
            ],
        ];

        $attached = 0;

        foreach ($barangDefinitions as $definition) {
            $barang = Barang::query()->firstOrCreate(
                ['no_index' => $definition['no_index']],
                [
                    'no_po' => $definition['no_po'],
                    'marking' => $marking->displayCode(),
                    'user_id' => $customer->id,
                    'customer_name' => $marking->customer_name,
                    'supplier_id' => $supplier?->id,
                    'gudang_china_id' => $gudang->id,
                    'warehouse_origin' => $gudang->name,
                    'tanggal_masuk' => $loadDate->copy()->subDays(5),
                    'destination' => 'Surabaya',
                    'form' => 'Box',
                    'cbm_type' => 'cbm',
                    'status' => Barang::STATUS_STUFFING,
                    'approval_status' => Barang::APPROVAL_APPROVED,
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                    'created_by' => $admin->id,
                ],
            );

            foreach ($definition['products'] as $sort => $productData) {
                BarangProduct::query()->updateOrCreate(
                    [
                        'barang_id' => $barang->id,
                        'product_name' => $productData['product_name'],
                    ],
                    array_merge($productData, [
                        'sort_order' => $sort,
                        'photo_path' => $placeholder,
                    ]),
                );
            }

            $barang->recalculateTotals();

            if (!$container->barangs()->where('barang_id', $barang->id)->exists()) {
                $container->barangs()->attach($barang->id, [
                    'loaded_at' => $loadDate,
                    'loaded_by' => $admin->id,
                ]);
                $attached++;
            }
        }

        $container->refresh();
        $barangCount = $container->barangs()->count();
        $productCount = BarangProduct::query()
            ->whereIn('barang_id', $container->barangs->pluck('id'))
            ->count();

        $detailUrl = url('/container/' . $container->id . '/detail');
        $excelUrl = url('/export/packing/' . $container->id . '/excel');

        $this->command?->info("ContainerLoadingDemoSeeder: container [{$container->code}] id={$container->id}");
        $this->command?->info("  Barang: {$barangCount} item(s), {$productCount} product line(s), {$attached} newly attached.");
        $this->command?->info("  Detail: {$detailUrl}");
        $this->command?->info("  Packing List Excel: {$excelUrl}");
    }

    private function ensurePlaceholderImage(): string
    {
        $path = 'barang-products/placeholder.png';

        if (!Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put(
                $path,
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
            );
        }

        return $path;
    }
}
