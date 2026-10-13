<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MasterBranch;
use App\Models\MasterCbmType;
use App\Models\MasterClassification;
use App\Models\MasterContainerType;
use App\Models\MasterCountUnit;
use App\Models\MasterDepartment;
use App\Models\MasterPackagingType;
use App\Models\MasterPricingItem;
use App\Models\MasterRoute;
use App\Models\Supplier;
use App\Models\Vessel;
use Illuminate\Database\Seeder;

final class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        // Cabang
        $branches = [
            ['code' => 'JKT', 'name' => 'Jakarta'],
            ['code' => 'SBY', 'name' => 'Surabaya'],
            ['code' => 'MDN', 'name' => 'Medan'],
            ['code' => 'SMG', 'name' => 'Semarang'],
            ['code' => 'BDG', 'name' => 'Bandung'],
            ['code' => 'MKS', 'name' => 'Makassar'],
            ['code' => 'BPN', 'name' => 'Balikpapan'],
            ['code' => 'PLB', 'name' => 'Palembang'],
        ];
        foreach ($branches as $b) {
            MasterBranch::firstOrCreate(['code' => $b['code']], $b);
        }

        // Departemen
        $departments = [
            'ACC HP & GARMENT', 'ACC MOBIL/MOTOR', 'ACC. HP', 'ALAT LISTRIK',
            'ALAT RUMAH TANGGA', 'BAHAN BANGUNAN', 'BRG UMUM', 'ELEKTRONIK',
            'FURNITURE', 'GARMENT', 'MAINAN', 'MOTOR & SPAREPART',
            'MOTORCYCLE PARTS', 'PACKAGING BAGS', 'SEPATU & SANDAL',
            'SPARE PART MOBIL', 'TEXTILE', 'KOSMETIK', 'FOOD & BEVERAGE',
            'CHEMICAL', 'STATIONERY',
        ];
        foreach ($departments as $d) {
            MasterDepartment::firstOrCreate(['name' => $d]);
        }

        // Rute
        $routes = [
            ['name' => 'CHINA - JAKARTA (FCL)',     'origin' => 'China', 'destination' => 'Jakarta',   'type' => 'FCL'],
            ['name' => 'CHINA - SURABAYA (FCL)',     'origin' => 'China', 'destination' => 'Surabaya',  'type' => 'FCL'],
            ['name' => 'CHINA - MEDAN (FCL)',        'origin' => 'China', 'destination' => 'Medan',     'type' => 'FCL'],
            ['name' => 'CHINA - SEMARANG (FCL)',     'origin' => 'China', 'destination' => 'Semarang',  'type' => 'FCL'],
            ['name' => 'CHINA - MAKASSAR (FCL)',     'origin' => 'China', 'destination' => 'Makassar',  'type' => 'FCL'],
            ['name' => 'CHINA - JAKARTA (LCL)',      'origin' => 'China', 'destination' => 'Jakarta',   'type' => 'LCL'],
            ['name' => 'CHINA - SURABAYA (LCL)',     'origin' => 'China', 'destination' => 'Surabaya',  'type' => 'LCL'],
            ['name' => 'CHINA - MEDAN (LCL)',        'origin' => 'China', 'destination' => 'Medan',     'type' => 'LCL'],
            ['name' => 'CHINA - BALIKPAPAN (FCL)',   'origin' => 'China', 'destination' => 'Balikpapan','type' => 'FCL'],
            ['name' => 'CHINA - PALEMBANG (FCL)',    'origin' => 'China', 'destination' => 'Palembang', 'type' => 'FCL'],
        ];
        foreach ($routes as $r) {
            MasterRoute::firstOrCreate(['name' => $r['name']], $r);
        }

        // Kapal
        $vessels = [
            ['code' => 'MV-JKT-01', 'name' => 'MV Jakarta Express', 'status' => 'active'],
            ['code' => 'MV-SBY-01', 'name' => 'MV Surabaya Star', 'status' => 'active'],
            ['code' => 'MV-MDN-01', 'name' => 'MV Medan Navigator', 'status' => 'active'],
            ['code' => 'MV-SMG-01', 'name' => 'MV Semarang Line', 'status' => 'active'],
            ['code' => 'MV-MKS-01', 'name' => 'MV Makassar Pioneer', 'status' => 'active'],
        ];
        foreach ($vessels as $vessel) {
            Vessel::firstOrCreate(['code' => $vessel['code']], $vessel);
        }

        // Tipe Kontainer
        $containerTypes = [
            ['code' => '20ft',    'label' => '20 Feet',           'length' => 589,  'width' => 234, 'height' => 238, 'volume' => 33.10],
            ['code' => '40ft',    'label' => '40 Feet',           'length' => 1203, 'width' => 234, 'height' => 238, 'volume' => 67.50],
            ['code' => '40ft HC', 'label' => '40 Feet High Cube', 'length' => 1203, 'width' => 234, 'height' => 269, 'volume' => 76.20],
            ['code' => '45ft',    'label' => '45 Feet',           'length' => 1350, 'width' => 234, 'height' => 269, 'volume' => 85.00],
        ];
        foreach ($containerTypes as $ct) {
            MasterContainerType::firstOrCreate(['code' => $ct['code']], $ct);
        }

        // Tipe Packaging
        $packagingTypes = ['Carton', 'Wooden', 'Pallet', 'Bag', 'Drum', 'Bundle', 'Loose', 'Crate'];
        foreach ($packagingTypes as $p) {
            MasterPackagingType::firstOrCreate(['name' => $p]);
        }

        // Tipe CBM
        $cbmTypes = ['CBM', 'Piece', 'Rim', 'Ton', 'Set'];
        foreach ($cbmTypes as $c) {
            MasterCbmType::firstOrCreate(['name' => $c]);
        }

        // Klasifikasi
        $classifications = ['Fragile', 'Hazardous', 'Oversize', 'Perishable', 'High Value', 'Normal'];
        foreach ($classifications as $cl) {
            MasterClassification::firstOrCreate(['name' => $cl]);
        }

        // Satuan Hitung
        $countUnits = ['Box', 'Set', 'Pcs', 'Roll', 'Pack', 'Lusin', 'Kodi', 'Kg'];
        foreach ($countUnits as $cu) {
            MasterCountUnit::firstOrCreate(['name' => $cu]);
        }

        $pricingItems = [
            ['name' => 'Umum', 'volume_price' => 500000, 'gw_price' => 10000],
            ['name' => 'Lartas', 'volume_price' => 600000, 'gw_price' => 17000],
            ['name' => 'FCL', 'volume_price' => 0, 'gw_price' => 0],
            ['name' => 'LCL', 'volume_price' => 0, 'gw_price' => 0],
            ['name' => 'Air Freight', 'volume_price' => 0, 'gw_price' => 0],
            ['name' => 'Ekspedisi Darat', 'volume_price' => 0, 'gw_price' => 0],
            ['name' => 'Handling', 'volume_price' => 0, 'gw_price' => 0],
        ];
        foreach ($pricingItems as $pricingItem) {
            MasterPricingItem::query()->updateOrCreate(
                ['name' => $pricingItem['name']],
                [
                    'volume_price' => $pricingItem['volume_price'],
                    'gw_price' => $pricingItem['gw_price'],
                    'is_active' => true,
                ],
            );
        }

        // Supplier
        $suppliers = [
            ['name' => 'Guangzhou Trading Co.',   'contact' => '+86-20-12345678', 'address' => 'Guangzhou, China'],
            ['name' => 'Yiwu International Ltd.', 'contact' => '+86-579-87654321','address' => 'Yiwu, Zhejiang, China'],
            ['name' => 'Shenzhen Electronics',    'contact' => '+86-755-11223344', 'address' => 'Shenzhen, Guangdong, China'],
            ['name' => 'Shanghai Import Export',   'contact' => '+86-21-55667788',  'address' => 'Shanghai, China'],
            ['name' => 'Ningbo Shipping Co.',      'contact' => '+86-574-99887766',  'address' => 'Ningbo, Zhejiang, China'],
        ];
        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(['name' => $s['name']], $s);
        }
    }
}
