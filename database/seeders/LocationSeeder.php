<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Province;
use Illuminate\Database\Seeder;

final class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // ── Indonesia ──
        $id = Country::firstOrCreate(['code' => 'ID'], ['name' => 'Indonesia', 'phone_code' => '+62']);

        $provinces_id = [
            '11' => ['name' => 'Aceh', 'cities' => ['Banda Aceh', 'Lhokseumawe', 'Langsa', 'Sabang', 'Meulaboh']],
            '12' => ['name' => 'Sumatera Utara', 'cities' => ['Medan', 'Binjai', 'Pematang Siantar', 'Tebing Tinggi', 'Padang Sidempuan', 'Sibolga']],
            '13' => ['name' => 'Sumatera Barat', 'cities' => ['Padang', 'Bukittinggi', 'Payakumbuh', 'Solok', 'Sawahlunto']],
            '14' => ['name' => 'Riau', 'cities' => ['Pekanbaru', 'Dumai']],
            '15' => ['name' => 'Jambi', 'cities' => ['Jambi', 'Sungai Penuh']],
            '16' => ['name' => 'Sumatera Selatan', 'cities' => ['Palembang', 'Lubuklinggau', 'Pagar Alam', 'Prabumulih']],
            '17' => ['name' => 'Bengkulu', 'cities' => ['Bengkulu']],
            '18' => ['name' => 'Lampung', 'cities' => ['Bandar Lampung', 'Metro']],
            '19' => ['name' => 'Kepulauan Bangka Belitung', 'cities' => ['Pangkal Pinang']],
            '21' => ['name' => 'Kepulauan Riau', 'cities' => ['Batam', 'Tanjung Pinang']],
            '31' => ['name' => 'DKI Jakarta', 'cities' => ['Jakarta Pusat', 'Jakarta Utara', 'Jakarta Barat', 'Jakarta Selatan', 'Jakarta Timur']],
            '32' => ['name' => 'Jawa Barat', 'cities' => ['Bandung', 'Bekasi', 'Bogor', 'Cimahi', 'Cirebon', 'Depok', 'Sukabumi', 'Tasikmalaya', 'Karawang', 'Subang']],
            '33' => ['name' => 'Jawa Tengah', 'cities' => ['Semarang', 'Solo', 'Magelang', 'Pekalongan', 'Salatiga', 'Tegal', 'Surakarta', 'Kudus', 'Purwokerto']],
            '34' => ['name' => 'DI Yogyakarta', 'cities' => ['Yogyakarta']],
            '35' => ['name' => 'Jawa Timur', 'cities' => ['Surabaya', 'Malang', 'Kediri', 'Blitar', 'Mojokerto', 'Pasuruan', 'Probolinggo', 'Madiun', 'Batu', 'Gresik', 'Sidoarjo']],
            '36' => ['name' => 'Banten', 'cities' => ['Serang', 'Cilegon', 'Tangerang', 'Tangerang Selatan']],
            '51' => ['name' => 'Bali', 'cities' => ['Denpasar', 'Singaraja', 'Tabanan']],
            '52' => ['name' => 'Nusa Tenggara Barat', 'cities' => ['Mataram', 'Bima']],
            '53' => ['name' => 'Nusa Tenggara Timur', 'cities' => ['Kupang']],
            '61' => ['name' => 'Kalimantan Barat', 'cities' => ['Pontianak', 'Singkawang']],
            '62' => ['name' => 'Kalimantan Tengah', 'cities' => ['Palangka Raya']],
            '63' => ['name' => 'Kalimantan Selatan', 'cities' => ['Banjarmasin', 'Banjarbaru']],
            '64' => ['name' => 'Kalimantan Timur', 'cities' => ['Samarinda', 'Balikpapan', 'Bontang']],
            '65' => ['name' => 'Kalimantan Utara', 'cities' => ['Tarakan']],
            '71' => ['name' => 'Sulawesi Utara', 'cities' => ['Manado', 'Bitung', 'Tomohon', 'Kotamobagu']],
            '72' => ['name' => 'Sulawesi Tengah', 'cities' => ['Palu']],
            '73' => ['name' => 'Sulawesi Selatan', 'cities' => ['Makassar', 'Parepare', 'Palopo']],
            '74' => ['name' => 'Sulawesi Tenggara', 'cities' => ['Kendari', 'Bau-Bau']],
            '75' => ['name' => 'Gorontalo', 'cities' => ['Gorontalo']],
            '76' => ['name' => 'Sulawesi Barat', 'cities' => ['Mamuju']],
            '81' => ['name' => 'Maluku', 'cities' => ['Ambon', 'Tual']],
            '82' => ['name' => 'Maluku Utara', 'cities' => ['Ternate', 'Tidore Kepulauan']],
            '91' => ['name' => 'Papua', 'cities' => ['Jayapura']],
            '92' => ['name' => 'Papua Barat', 'cities' => ['Manokwari', 'Sorong']],
            '93' => ['name' => 'Papua Selatan', 'cities' => ['Merauke']],
            '94' => ['name' => 'Papua Tengah', 'cities' => ['Nabire']],
            '95' => ['name' => 'Papua Pegunungan', 'cities' => ['Jayawijaya']],
            '96' => ['name' => 'Papua Barat Daya', 'cities' => ['Sorong Selatan']],
        ];

        foreach ($provinces_id as $code => $pData) {
            $prov = Province::firstOrCreate(
                ['country_id' => $id->id, 'code' => $code],
                ['name' => $pData['name']]
            );
            foreach ($pData['cities'] as $cityName) {
                City::firstOrCreate(
                    ['province_id' => $prov->id, 'name' => $cityName],
                    ['type' => 'kota']
                );
            }
        }

        // ── China ──
        $cn = Country::firstOrCreate(['code' => 'CN'], ['name' => 'China', 'phone_code' => '+86']);

        $provinces_cn = [
            'GD' => ['name' => 'Guangdong', 'cities' => ['Guangzhou', 'Shenzhen', 'Dongguan', 'Foshan', 'Zhongshan', 'Zhuhai', 'Huizhou', 'Jiangmen', 'Shantou', 'Zhaoqing']],
            'ZJ' => ['name' => 'Zhejiang', 'cities' => ['Hangzhou', 'Ningbo', 'Wenzhou', 'Yiwu', 'Jinhua', 'Taizhou', 'Shaoxing', 'Jiaxing']],
            'JS' => ['name' => 'Jiangsu', 'cities' => ['Nanjing', 'Suzhou', 'Wuxi', 'Changzhou', 'Nantong', 'Xuzhou', 'Lianyungang']],
            'SH' => ['name' => 'Shanghai', 'cities' => ['Shanghai']],
            'BJ' => ['name' => 'Beijing', 'cities' => ['Beijing']],
            'TJ' => ['name' => 'Tianjin', 'cities' => ['Tianjin']],
            'FJ' => ['name' => 'Fujian', 'cities' => ['Fuzhou', 'Xiamen', 'Quanzhou', 'Putian', 'Zhangzhou']],
            'SD' => ['name' => 'Shandong', 'cities' => ['Qingdao', 'Jinan', 'Yantai', 'Weihai', 'Rizhao', 'Linyi']],
            'HB' => ['name' => 'Hubei', 'cities' => ['Wuhan', 'Yichang', 'Xiangyang']],
            'HN' => ['name' => 'Hunan', 'cities' => ['Changsha', 'Zhuzhou', 'Xiangtan']],
            'SC' => ['name' => 'Sichuan', 'cities' => ['Chengdu', 'Mianyang', 'Leshan']],
            'CQ' => ['name' => 'Chongqing', 'cities' => ['Chongqing']],
            'LN' => ['name' => 'Liaoning', 'cities' => ['Dalian', 'Shenyang', 'Yingkou']],
            'HE' => ['name' => 'Hebei', 'cities' => ['Shijiazhuang', 'Tangshan', 'Qinhuangdao']],
            'GX' => ['name' => 'Guangxi', 'cities' => ['Nanning', 'Guilin', 'Liuzhou', 'Beihai', 'Fangchenggang']],
            'HA' => ['name' => 'Hainan', 'cities' => ['Haikou', 'Sanya']],
            'AH' => ['name' => 'Anhui', 'cities' => ['Hefei', 'Wuhu', 'Maanshan']],
            'JX' => ['name' => 'Jiangxi', 'cities' => ['Nanchang', 'Jiujiang', 'Ganzhou']],
            'HI' => ['name' => 'Henan', 'cities' => ['Zhengzhou', 'Luoyang', 'Kaifeng']],
            'SX' => ['name' => 'Shanxi', 'cities' => ['Taiyuan', 'Datong']],
            'SN' => ['name' => 'Shaanxi', 'cities' => ['Xian', 'Xianyang']],
            'YN' => ['name' => 'Yunnan', 'cities' => ['Kunming', 'Dali']],
            'GZ' => ['name' => 'Guizhou', 'cities' => ['Guiyang', 'Zunyi']],
        ];

        foreach ($provinces_cn as $code => $pData) {
            $prov = Province::firstOrCreate(
                ['country_id' => $cn->id, 'code' => $code],
                ['name' => $pData['name']]
            );
            foreach ($pData['cities'] as $cityName) {
                City::firstOrCreate(
                    ['province_id' => $prov->id, 'name' => $cityName],
                    ['type' => 'kota']
                );
            }
        }

        // ── Common trade countries ──
        $others = [
            ['code' => 'SG', 'name' => 'Singapore',    'phone_code' => '+65'],
            ['code' => 'MY', 'name' => 'Malaysia',     'phone_code' => '+60'],
            ['code' => 'TH', 'name' => 'Thailand',     'phone_code' => '+66'],
            ['code' => 'VN', 'name' => 'Vietnam',      'phone_code' => '+84'],
            ['code' => 'PH', 'name' => 'Philippines',  'phone_code' => '+63'],
            ['code' => 'KR', 'name' => 'South Korea',  'phone_code' => '+82'],
            ['code' => 'JP', 'name' => 'Japan',        'phone_code' => '+81'],
            ['code' => 'TW', 'name' => 'Taiwan',       'phone_code' => '+886'],
            ['code' => 'HK', 'name' => 'Hong Kong',    'phone_code' => '+852'],
            ['code' => 'IN', 'name' => 'India',        'phone_code' => '+91'],
            ['code' => 'US', 'name' => 'United States', 'phone_code' => '+1'],
            ['code' => 'AE', 'name' => 'UAE',          'phone_code' => '+971'],
            ['code' => 'AU', 'name' => 'Australia',    'phone_code' => '+61'],
        ];

        foreach ($others as $c) {
            Country::firstOrCreate(['code' => $c['code']], $c);
        }
    }
}
