<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BarangProduct;
use Tests\TestCase;

final class BarangProductFormOrderTest extends TestCase
{
    public function test_ordered_for_form_places_sub_items_after_parent(): void
    {
        $parent = new BarangProduct([
            'parent_id' => null,
            'sort_order' => 1,
            'product_name' => 'Parent',
        ]);
        $parent->id = 10;
        $sub = new BarangProduct([
            'parent_id' => 10,
            'sort_order' => 0,
            'product_name' => 'Sub',
        ]);
        $sub->id = 11;

        $ordered = BarangProduct::orderedForForm([$sub, $parent]);

        $this->assertSame([10, 11], $ordered->pluck('id')->all());
    }

    public function test_ordered_for_form_keeps_multiple_parents_and_subs(): void
    {
        $parentA = new BarangProduct(['parent_id' => null, 'sort_order' => 0]);
        $parentA->id = 1;
        $subA = new BarangProduct(['parent_id' => 1, 'sort_order' => 1]);
        $subA->id = 2;
        $parentB = new BarangProduct(['parent_id' => null, 'sort_order' => 2]);
        $parentB->id = 3;
        $subB = new BarangProduct(['parent_id' => 3, 'sort_order' => 0]);
        $subB->id = 4;

        $ordered = BarangProduct::orderedForForm([$subB, $parentB, $subA, $parentA]);

        $this->assertSame([1, 2, 3, 4], $ordered->pluck('id')->all());
    }
}
