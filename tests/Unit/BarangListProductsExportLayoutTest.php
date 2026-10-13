<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionMethod;
use Tests\TestCase;

final class BarangListProductsExportLayoutTest extends TestCase
{
    public function test_parent_with_subs_matches_template_layout(): void
    {
        $barang = new Barang([
            'no_index' => 'testing 1',
            'marking' => '',
            'cbm_type' => 'Lartas Berat',
        ]);
        $barang->id = 1;

        $parent = new BarangProduct([
            'parent_id' => null,
            'product_name' => 'test',
            'ctns' => 2,
            'pcs_per_box' => 2,
            'length_cm' => 14,
            'width_cm' => 15,
            'height_cm' => 6,
            'gross_weight_kg' => 13,
        ]);
        $parent->id = 10;

        $sub = new BarangProduct([
            'parent_id' => 10,
            'product_name' => 'test.2.0',
            'pcs_per_box' => 20,
        ]);
        $sub->id = 11;

        $barang->setRelation('products', new EloquentCollection([$parent, $sub]));

        $path = $this->exportToTempFile(new BarangListProductsExport(new EloquentCollection([$barang])));
        $sheet = IOFactory::load($path)->getSheetByName('Daftar Kirim');

        $this->assertSame('Total coly', $sheet->getCell('E1')->getValue());
        $this->assertSame('test.2.0', $sheet->getCell('D3')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('E3')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('F3')->getValue());
        $this->assertSame(14, (int) $sheet->getCell('I3')->getValue());
        $this->assertGreaterThan(0, (float) $sheet->getCell('L3')->getValue());
        $this->assertSame(20, (int) $sheet->getCell('G3')->getValue());
        $this->assertSame(40, (int) $sheet->getCell('H3')->getValue());
        $this->assertNoDiagonalBorders($sheet, 3, 3);

        @unlink($path);
    }

    public function test_export_data_rows_have_no_diagonal_borders(): void
    {
        $barang = new Barang(['no_index' => 'testing A', 'marking' => '', 'cbm_type' => 'Lartas Berat']);
        $barang->id = 2;

        $parent = new BarangProduct([
            'parent_id' => null,
            'product_name' => 'testing A',
            'ctns' => 2,
            'length_cm' => 20,
            'width_cm' => 29,
            'height_cm' => 50,
            'gross_weight_kg' => 13,
        ]);
        $parent->id = 20;

        $subs = [];
        foreach ([['tes.1.', 50], ['tes', 50], ['tes.2.', 50]] as $index => [$name, $pcs]) {
            $sub = new BarangProduct([
                'parent_id' => 20,
                'product_name' => $name,
                'pcs_per_box' => $pcs,
            ]);
            $sub->id = 21 + $index;
            $subs[] = $sub;
        }

        $barang->setRelation('products', new EloquentCollection(array_merge([$parent], $subs)));

        $path = $this->exportToTempFile(new BarangListProductsExport(new EloquentCollection([$barang])));
        $sheet = IOFactory::load($path)->getSheetByName('Daftar Kirim');

        $this->assertNoDiagonalBorders($sheet, 3, 5);

        @unlink($path);
    }

    private function assertNoDiagonalBorders(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $startRow, int $endRow): void
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            foreach (range('A', 'R') as $column) {
                $borders = $sheet->getStyle($column.$row)->getBorders();
                $this->assertSame(
                    \PhpOffice\PhpSpreadsheet\Style\Borders::DIAGONAL_NONE,
                    $borders->getDiagonalDirection(),
                    "Unexpected diagonal border on {$column}{$row}",
                );
                $this->assertSame(
                    \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                    $borders->getDiagonal()->getBorderStyle(),
                    "Unexpected diagonal border style on {$column}{$row}",
                );
            }
        }
    }

    public function test_standalone_parent_row_includes_total_coly_and_logistics(): void
    {
        $barang = new Barang(['no_index' => 'testing 1', 'marking' => '']);
        $barang->id = 1;

        $product = new BarangProduct([
            'parent_id' => null,
            'product_name' => 'testing3.1',
            'ctns' => 2,
            'pcs_per_box' => 2,
            'length_cm' => 20,
            'width_cm' => 29,
            'height_cm' => 50,
            'gross_weight_kg' => 13,
        ]);
        $product->id = 20;

        $barang->setRelation('products', new EloquentCollection([$product]));

        $path = $this->exportToTempFile(new BarangListProductsExport(new EloquentCollection([$barang])));
        $sheet = IOFactory::load($path)->getSheetByName('Daftar Kirim');

        $this->assertSame(2, (int) $sheet->getCell('E3')->getValue());
        $this->assertSame(20, (int) $sheet->getCell('I3')->getValue());
        $this->assertGreaterThan(0, (float) $sheet->getCell('L3')->getValue());

        @unlink($path);
    }

    public function test_coly_final_is_sum_of_parent_ctns(): void
    {
        $barang = new Barang(['id' => 2, 'no_index' => 'X', 'marking' => '']);
        $barang->setRelation('products', new EloquentCollection([
            new BarangProduct(['id' => 1, 'parent_id' => null, 'ctns' => 2, 'product_name' => 'A']),
            new BarangProduct(['id' => 2, 'parent_id' => null, 'ctns' => 3, 'product_name' => 'B']),
        ]));

        $export = new BarangListProductsExport(new EloquentCollection([$barang]));
        $method = new ReflectionMethod($export, 'colyFinalForBarang');
        $method->setAccessible(true);

        $this->assertSame(5, $method->invoke($export, $barang));
    }

    private function exportToTempFile(BarangListProductsExport $export): string
    {
        $method = new ReflectionMethod($export, 'buildSpreadsheet');
        $method->setAccessible(true);
        $spreadsheet = $method->invoke($export);

        $path = storage_path('app/testing/'.uniqid('daftar-kirim-', true).'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
