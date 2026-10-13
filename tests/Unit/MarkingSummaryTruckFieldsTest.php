<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\DeliveryTripController;
use App\Models\DeliveryTripMarkingSummary;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MarkingSummaryTruckFieldsTest extends TestCase
{
    public function test_preserves_existing_truck_fields_when_request_sends_empty_values(): void
    {
        $existing = new DeliveryTripMarkingSummary([
            'truck_plate' => 'B 1234 AB',
            'driver_name' => 'Budi',
            'driver_phone' => '08123456789',
        ]);

        $fields = $this->resolveTruckFields([
            'truck_plate' => '',
            'driver_name' => '',
            'driver_phone' => '',
        ], $existing);

        $this->assertSame('B 1234 AB', $fields['truck_plate']);
        $this->assertSame('Budi', $fields['driver_name']);
        $this->assertSame('08123456789', $fields['driver_phone']);
    }

    public function test_overwrites_truck_fields_when_request_has_new_values(): void
    {
        $existing = new DeliveryTripMarkingSummary([
            'truck_plate' => 'B 1234 AB',
            'driver_name' => 'Budi',
            'driver_phone' => '08123456789',
        ]);

        $fields = $this->resolveTruckFields([
            'truck_plate' => 'L 5678 CD',
            'driver_name' => 'Andi',
            'driver_phone' => '08111111111',
        ], $existing);

        $this->assertSame('L 5678 CD', $fields['truck_plate']);
        $this->assertSame('Andi', $fields['driver_name']);
        $this->assertSame('08111111111', $fields['driver_phone']);
    }

    public function test_uses_request_values_when_no_existing_summary(): void
    {
        $fields = $this->resolveTruckFields([
            'truck_plate' => 'B 9999 ZZ',
            'driver_name' => 'Joko',
            'driver_phone' => '',
        ], null);

        $this->assertSame('B 9999 ZZ', $fields['truck_plate']);
        $this->assertSame('Joko', $fields['driver_name']);
        $this->assertNull($fields['driver_phone']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{truck_plate: ?string, driver_name: ?string, driver_phone: ?string}
     */
    private function resolveTruckFields(array $data, ?DeliveryTripMarkingSummary $existing): array
    {
        $method = new ReflectionMethod(DeliveryTripController::class, 'markingSummaryTruckFields');
        $method->setAccessible(true);

        /** @var array{truck_plate: ?string, driver_name: ?string, driver_phone: ?string} $result */
        $result = $method->invoke(new DeliveryTripController(), $data, $existing);

        return $result;
    }
}
