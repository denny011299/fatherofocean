<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Marking;
use App\Models\Supplier;
use App\Models\TrackingStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::where('username', 'customer')->first();
        $admin = User::where('username', 'admin')->first();
        $supplier = Supplier::first();

        if (!$customer || !$admin) {
            $this->command->warn('Run DatabaseSeeder first.');
            return;
        }

        $markings = Marking::where('user_id', $customer->id)->pluck('code')->toArray();
        if (empty($markings)) {
            $markings = ['CUST-001'];
        }

        $container = Container::firstOrCreate(['partai' => 'CNT-2026-001'], [
            'container_no' => 'MSKU7234561',
            'no_bl' => 'SL-88123',
            'type' => '40HC',
            'branch' => 'SBY',
            'max_length' => 1203,
            'max_width' => 235,
            'max_height' => 269,
            'max_volume' => 76.00,
            'load_date' => '2026-05-01',
            'etd' => '2026-05-10',
            'eta' => '2026-05-25',
            'created_by' => $admin->id,
        ]);

        $container2 = Container::firstOrCreate(['partai' => 'CNT-2026-002'], [
            'container_no' => 'TCLU4456789',
            'no_bl' => 'SL-99456',
            'type' => '20GP',
            'branch' => 'JKT',
            'max_length' => 590,
            'max_width' => 235,
            'max_height' => 239,
            'max_volume' => 33.00,
            'load_date' => '2026-05-05',
            'etd' => '2026-05-15',
            'eta' => '2026-06-01',
            'created_by' => $admin->id,
        ]);

        $items = [
            [
                'no_index' => 'BRG-2026-0001', 'no_po' => 'PO-1001',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 10, 'pcs' => 200, 'price' => 5000000,
                'total_cbm' => 2.5, 'total_gross_weight' => 150,
                'cbm_type' => 'cbm',
                'material' => 'Elektronik - Lampu LED', 'form' => 'Box',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_BONGKAR,
                'container_id' => $container->id,
                'notes' => 'Barang sudah diterima customer',
            ],
            [
                'no_index' => 'BRG-2026-0002', 'no_po' => 'PO-1002',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 5, 'pcs' => 50, 'price' => 15000000,
                'total_cbm' => 3.2, 'total_gross_weight' => 250,
                'cbm_type' => 'cbm',
                'material' => 'Spare Part Motor', 'form' => 'Pallet',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_ETD,
                'container_id' => $container->id,
                'notes' => 'Dalam perjalanan kapal',
            ],
            [
                'no_index' => 'BRG-2026-0003', 'no_po' => 'PO-1003',
                'marking' => $markings[array_rand($markings)], 'warehouse_origin' => 'JKT',
                'ctns' => 20, 'pcs' => 400, 'price' => 8000000,
                'total_cbm' => 5.0, 'total_gross_weight' => 320,
                'cbm_type' => 'cbm',
                'material' => 'Tekstil - Kain Polyester', 'form' => 'Box',
                'destination' => 'Jakarta', 'status' => Barang::STATUS_ETD,
                'container_id' => $container2->id,
                'notes' => 'ETD minggu depan',
            ],
            [
                'no_index' => 'BRG-2026-0004', 'no_po' => 'PO-1004',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 8, 'pcs' => 160, 'price' => 3500000,
                'total_cbm' => 1.8, 'total_gross_weight' => 95,
                'cbm_type' => 'cbm',
                'material' => 'Aksesoris HP', 'form' => 'Box',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_STUFFING,
                'container_id' => $container->id,
                'notes' => 'Sudah dimuat ke container',
            ],
            [
                'no_index' => 'BRG-2026-0005', 'no_po' => 'PO-1005',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 15, 'pcs' => 300, 'price' => 12000000,
                'total_cbm' => 4.5, 'total_gross_weight' => 280,
                'cbm_type' => 'cbm',
                'material' => 'Peralatan Dapur Stainless', 'form' => 'Box',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_ETA,
                'container_id' => $container->id,
                'notes' => 'Sudah tiba di gudang Indo',
            ],
            [
                'no_index' => 'BRG-2026-0006', 'no_po' => 'PO-1006',
                'marking' => $markings[array_rand($markings)], 'warehouse_origin' => 'JKT',
                'ctns' => 3, 'pcs' => 30, 'price' => 25000000,
                'total_cbm' => 6.0, 'total_gross_weight' => 500,
                'cbm_type' => 'cbm',
                'material' => 'Mesin Cetak Digital', 'form' => 'Pallet',
                'destination' => 'Jakarta', 'status' => Barang::STATUS_STUFFING,
                'container_id' => $container2->id,
                'notes' => 'Handle with care - fragile',
            ],
            [
                'no_index' => 'BRG-2026-0007',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 12, 'pcs' => 240, 'price' => 6000000,
                'total_cbm' => 2.0, 'total_gross_weight' => 120,
                'cbm_type' => 'cbm',
                'material' => 'Mainan Anak', 'form' => 'Box',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_STUFFING,
                'notes' => 'Masih di gudang China',
            ],
            [
                'no_index' => 'BRG-2026-0008',
                'marking' => $markings[0], 'warehouse_origin' => 'SBY',
                'ctns' => 6, 'pcs' => 60, 'price' => 9000000,
                'total_cbm' => 3.5, 'total_gross_weight' => 200,
                'cbm_type' => 'cbm',
                'material' => 'Sepatu Import', 'form' => 'Box',
                'destination' => 'Surabaya', 'status' => Barang::STATUS_STUFFING,
                'notes' => 'Menunggu muat ke container',
            ],
        ];

        foreach ($items as $item) {
            $containerId = $item['container_id'] ?? null;
            unset($item['container_id']);

            $barang = Barang::firstOrCreate(
                ['no_index' => $item['no_index']],
                array_merge($item, [
                    'user_id' => $customer->id,
                    'supplier_id' => $supplier?->id,
                    'tanggal_masuk' => now()->subDays(rand(5, 30)),
                    'created_by' => $admin->id,
                ])
            );

            if ($containerId) {
                $cont = Container::find($containerId);
                if ($cont && !$cont->barangs()->where('barang_id', $barang->id)->exists()) {
                    $cont->barangs()->attach($barang->id, [
                        'loaded_at' => now()->subDays(rand(3, 15)),
                        'loaded_by' => $admin->id,
                    ]);
                }
            }

            $this->seedTracking($barang, $admin->id);
        }

        $this->command->info('Sample data seeded: 2 containers, 8 barang with tracking history.');

        $this->call(BarangProductSeeder::class);
    }

    private function seedTracking(Barang $barang, int $adminId): void
    {
        if ($barang->trackingStatuses()->exists()) {
            return;
        }

        $statusFlow = [
            Barang::STATUS_STUFFING => [
                ['status' => Barang::STATUS_STUFFING, 'location' => 'Gudang China, Guangzhou', 'description' => 'Proses stuffing di gudang China'],
            ],
            Barang::STATUS_ETD => [
                ['status' => Barang::STATUS_STUFFING, 'location' => 'Gudang China, Guangzhou', 'description' => 'Proses stuffing di gudang China'],
                ['status' => Barang::STATUS_ETD, 'location' => 'Pelabuhan Shenzhen', 'description' => 'Kapal berangkat (ETD)'],
            ],
            Barang::STATUS_ETA => [
                ['status' => Barang::STATUS_STUFFING, 'location' => 'Gudang China, Guangzhou', 'description' => 'Proses stuffing di gudang China'],
                ['status' => Barang::STATUS_ETD, 'location' => 'Laut China Selatan', 'description' => 'Kapal berangkat (ETD)'],
                ['status' => Barang::STATUS_ETA, 'location' => 'Gudang Indonesia, Surabaya', 'description' => 'Barang tiba (ETA)'],
            ],
            Barang::STATUS_BONGKAR => [
                ['status' => Barang::STATUS_STUFFING, 'location' => 'Gudang China, Guangzhou', 'description' => 'Proses stuffing di gudang China'],
                ['status' => Barang::STATUS_ETD, 'location' => 'Laut China Selatan', 'description' => 'Kapal berangkat (ETD)'],
                ['status' => Barang::STATUS_ETA, 'location' => 'Gudang Indonesia, Surabaya', 'description' => 'Barang tiba (ETA)'],
                ['status' => Barang::STATUS_BONGKAR, 'location' => 'Alamat Customer', 'description' => 'Barang selesai bongkar'],
            ],
        ];

        $tracks = $statusFlow[$barang->status] ?? $statusFlow[Barang::STATUS_STUFFING];
        $baseDate = $barang->tanggal_masuk ?? now()->subDays(20);

        foreach ($tracks as $i => $track) {
            TrackingStatus::create([
                'barang_id' => $barang->id,
                'status' => $track['status'],
                'location' => $track['location'],
                'description' => $track['description'],
                'created_by' => $adminId,
                'created_at' => $baseDate->copy()->addDays($i * 3),
                'updated_at' => $baseDate->copy()->addDays($i * 3),
            ]);
        }
    }
}
