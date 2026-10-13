<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\BarangController;
use App\Models\Barang;
use Illuminate\Http\Request;

/**
 * Delegates barang update execution to BarangController without constructor cycles.
 */
final class BarangUpdateExecutor
{
    public function execute(Barang $barang, Request $request): Barang
    {
        /** @var BarangController $controller */
        $controller = app(BarangController::class);

        return $controller->executeBarangUpdate($barang, $request);
    }
}
