<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\MarkingController;
use App\Models\Marking;
use App\Models\MarkingPricing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class MarkingPricingItemNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetMarkingSchemaCache();

        Schema::dropIfExists('marking_pricings');
        Schema::create('marking_pricings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marking_id');
            $table->unsignedBigInteger('pricing_item_id');
            $table->string('item_name')->nullable();
            $table->decimal('volume_price', 12, 2)->default(0);
            $table->decimal('gw_price', 12, 2)->default(0);
            $table->timestamps();
        });

        $this->resetMarkingSchemaCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('marking_pricings');
        $this->resetMarkingSchemaCache();
        parent::tearDown();
    }

    public function test_pricing_row_attributes_trim_and_store_item_name(): void
    {
        $method = new ReflectionMethod(MarkingController::class, 'pricingRowAttributes');
        $method->setAccessible(true);

        /** @var array<string, mixed> $attrs */
        $attrs = $method->invoke(null, [
            'pricing_item_id' => 7,
            'item_name' => '  Barang Umum Customer  ',
            'volume_price' => 500000,
            'gw_price' => 10000,
        ]);

        $this->assertSame(7, $attrs['pricing_item_id']);
        $this->assertSame('Barang Umum Customer', $attrs['item_name']);
        $this->assertSame(500000.0, $attrs['volume_price']);
        $this->assertSame(10000.0, $attrs['gw_price']);
    }

    public function test_pricing_row_attributes_null_when_item_name_blank(): void
    {
        $method = new ReflectionMethod(MarkingController::class, 'pricingRowAttributes');
        $method->setAccessible(true);

        /** @var array<string, mixed> $attrs */
        $attrs = $method->invoke(null, [
            'pricing_item_id' => 3,
            'item_name' => '   ',
            'volume_price' => 1,
            'gw_price' => 2,
        ]);

        $this->assertNull($attrs['item_name']);
    }

    public function test_marking_pricing_persists_item_name(): void
    {
        $pricing = MarkingPricing::query()->create([
            'marking_id' => 1,
            'pricing_item_id' => 2,
            'item_name' => 'Nama dari Marking',
            'volume_price' => 420000,
            'gw_price' => 8500,
        ]);

        $this->assertSame(
            'Nama dari Marking',
            MarkingPricing::query()->whereKey($pricing->id)->value('item_name'),
        );
    }

    private function resetMarkingSchemaCache(): void
    {
        $ref = new ReflectionClass(Marking::class);
        $prop = $ref->getProperty('schemaColumnCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
