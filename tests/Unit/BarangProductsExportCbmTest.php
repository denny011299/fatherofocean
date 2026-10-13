<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BarangProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use App\Support\CbmFormat;
use App\Support\ExcelDecimalFormat;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionMethod;
use Tests\TestCase;

final class BarangProductsExportCbmTest extends TestCase
{
    public function test_cbm_column_uses_three_decimal_format_like_form(): void
    {
        $barang = new Barang([
            'no_index' => 'testing A',
            'marking' => 'BS/5758/SEA',
        ]);
        $barang->id = 343;

        $barang->setRelation('products', new EloquentCollection([
            tap(new BarangProduct([
                'sort_order' => 0,
                'product_name' => 'test',
                'ctns' => 2,
                'pcs_per_box' => 2,
                'gross_weight_kg' => 13,
                'length_cm' => 14,
                'width_cm' => 15,
                'height_cm' => 6,
                'price_per_unit_rmb' => 10,
            ]), static fn (BarangProduct $p) => $p->id = 1),
            tap(new BarangProduct([
                'sort_order' => 1,
                'product_name' => 'tes.2',
                'ctns' => 2,
                'pcs_per_box' => 0,
                'gross_weight_kg' => 10,
                'length_cm' => 50,
                'width_cm' => 50,
                'height_cm' => 50,
                'price_per_unit_rmb' => 10,
            ]), static fn (BarangProduct $p) => $p->id = 2),
            tap(new BarangProduct([
                'sort_order' => 2,
                'product_name' => 'Large box',
                'ctns' => 1,
                'pcs_per_box' => 50,
                'gross_weight_kg' => 0,
                'length_cm' => 100,
                'width_cm' => 100,
                'height_cm' => 100,
                'price_per_unit_rmb' => 10,
            ]), static fn (BarangProduct $p) => $p->id = 3),
        ]));

        $export = (new \ReflectionClass(BarangProductsExport::class))->newInstanceWithoutConstructor();
        $barangProperty = new \ReflectionProperty(BarangProductsExport::class, 'barang');
        $barangProperty->setAccessible(true);
        $barangProperty->setValue($export, $barang);

        $path = $this->exportToTempFile($export);
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame(
            ExcelDecimalFormat::DECIMAL_3,
            $sheet->getStyle('J5')->getNumberFormat()->getFormatCode(),
        );
        $this->assertSame(
            0.002,
            CbmFormat::truncate((float) $sheet->getCell('J5')->getValue()),
        );
        $this->assertSame(
            0.250,
            CbmFormat::truncate((float) $sheet->getCell('J6')->getValue()),
        );
        $this->assertSame(
            1.000,
            CbmFormat::truncate((float) $sheet->getCell('J7')->getValue()),
        );

        @unlink($path);
    }

    private function exportToTempFile(BarangProductsExport $export): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $method = new ReflectionMethod($export, 'buildSheet');
        $method->setAccessible(true);
        $method->invoke($export, $sheet);

        $path = storage_path('app/testing/'.uniqid('barang-products-', true).'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
