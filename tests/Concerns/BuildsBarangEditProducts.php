<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Support\BarangEditRequestService;

/** @internal */
trait BuildsBarangEditProducts
{
  /**
   * @param  list<array<string, mixed>>  $proposed
   * @param  list<array<string, mixed>>  $snapshot
   * @return list<array<string, mixed>>
   */
    protected function buildProposedProducts(array $proposed, array $snapshot): array
    {
        $service = app(BarangEditRequestService::class);
        $method = new \ReflectionMethod($service, 'buildProposedProducts');
        $method->setAccessible(true);

        return $method->invoke($service, $proposed, $snapshot);
    }

    /** @return array<string, mixed> */
    protected function parentRow(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'product_name' => 'Box ' . $id,
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'parent_id' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function subRow(array $overrides = []): array
    {
        return array_merge([
            'is_sub_item' => 1,
            'product_name' => 'Sub',
            'pcs_per_box' => 4,
            'price_per_unit_rmb' => 10,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function newParentRow(array $overrides = []): array
    {
        return array_merge([
            'product_name' => 'New box',
            'ctns' => 2,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ], $overrides);
    }
}
