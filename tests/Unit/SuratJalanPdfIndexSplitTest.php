<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMarkingSummary;
use App\Support\DeliverySplitDeliveries;
use App\Support\SuratJalanPdfData;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

final class SuratJalanPdfIndexSplitTest extends TestCase
{
    public function test_index_split_groups_rows_per_address_page(): void
    {
        $barangA = $this->makeBarang(1, '1704.47', 'Sandals A', 3);
        $barangB = $this->makeBarang(2, '2404.43', 'Sandals B', 2);

        $trip = new DeliveryTrip([
            'trip_no' => 'TRIP-1',
            'truck_plate' => 'B 1234 XY',
            'driver_name' => 'Supir',
        ]);
        $trip->setRelation('barangs', collect([$barangA, $barangB]));
        $trip->setRelation('container', new Container(['branch' => 'SBY']));

        $summary = new DeliveryTripMarkingSummary([
            'marking_key' => 'DBS',
            'customer_address' => 'Alamat default',
            'split_deliveries' => [
                [
                    'address' => 'Jl. Surabaya 1',
                    'receiver_name' => 'Budi',
                    'receiver_phone' => '0811',
                    'barang_ids' => [1],
                ],
                [
                    'address' => 'Jl. Jakarta 2',
                    'receiver_name' => 'Ani',
                    'receiver_phone' => '0812',
                    'barang_ids' => [2],
                ],
            ],
        ]);
        $trip->setRelation('markingSummaries', collect([$summary]));

        $splits = DeliverySplitDeliveries::fromSummaryRow($summary);
        $summaryFields = [
            'marking_code' => 'DBS',
            'alamat' => 'Alamat default',
            'nama_penerima' => '',
            'notes' => '',
        ];

        $method = new ReflectionMethod(SuratJalanPdfData::class, 'buildPdfPayloadForIndexSplits');
        $method->setAccessible(true);

        $payload = $method->invoke(
            null,
            $trip,
            collect([$barangA, $barangB]),
            $summaryFields,
            $splits,
            'DBS',
            1,
            1,
        );

        $this->assertCount(2, $payload['pages']);
        $this->assertSame('Jl. Surabaya 1', $payload['pages'][0]['header']['alamat']);
        $this->assertSame('1704.47', $payload['pages'][0]['rows'][0]['index']);
        $this->assertSame('Jl. Jakarta 2', $payload['pages'][1]['header']['alamat']);
        $this->assertSame('2404.43', $payload['pages'][1]['rows'][0]['index']);
    }

    public function test_uses_index_mode_when_barang_ids_present(): void
    {
        $splits = DeliverySplitDeliveries::normalize([
            ['address' => 'A', 'barang_ids' => [10]],
        ]);

        $this->assertTrue(DeliverySplitDeliveries::usesIndexMode($splits));
    }

    public function test_coly_mode_when_no_barang_ids(): void
    {
        $splits = DeliverySplitDeliveries::normalize([
            ['address' => 'A', 'coly' => 5],
        ]);

        $this->assertFalse(DeliverySplitDeliveries::usesIndexMode($splits));
    }

    private function makeBarang(int $id, string $noIndex, string $productName, int $ctns): Barang
    {
        $barang = new Barang([
            'no_index' => $noIndex,
            'marking' => 'DBS',
            'ctns' => $ctns,
            'total_cbm' => 1.1,
            'total_gross_weight' => 50,
            'keterangan' => '',
        ]);
        $barang->setAttribute('id', $id);
        $barang->setRelation('products', collect([
            new BarangProduct(['product_name' => $productName, 'sort_order' => 1]),
        ]));

        return $barang;
    }
}
