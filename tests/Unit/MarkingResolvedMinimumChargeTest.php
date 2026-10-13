<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Marking;
use App\Models\MarkingPricing;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

final class MarkingResolvedMinimumChargeTest extends TestCase
{
    /** @param array<string, bool> $columns */
    private function seedSchemaCache(array $columns): void
    {
        $ref = new ReflectionClass(Marking::class);
        $prop = $ref->getProperty('schemaColumnCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $columns);
    }

    public function test_resolved_minimum_charge_uses_marking_column_when_present(): void
    {
        $this->seedSchemaCache(['markings.minimum_charge' => true]);

        $marking = new Marking(['minimum_charge' => 150.5]);
        $marking->setRelation('pricings', new Collection([
            (new MarkingPricing())->forceFill(['minimum_charge' => 99]),
        ]));

        $this->assertSame('150.50', $marking->resolvedMinimumCharge());
    }

    public function test_resolved_minimum_charge_falls_back_to_pricing_max(): void
    {
        $this->seedSchemaCache(['markings.minimum_charge' => false]);

        $marking = new Marking;
        $marking->setRelation('pricings', new Collection([
            (new MarkingPricing())->forceFill(['minimum_charge' => 50]),
            (new MarkingPricing())->forceFill(['minimum_charge' => 120]),
        ]));

        $this->assertSame('120', $marking->resolvedMinimumCharge());
    }
}
