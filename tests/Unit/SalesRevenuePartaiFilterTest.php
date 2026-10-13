<?php



declare(strict_types=1);



namespace Tests\Unit;



use App\Models\Barang;

use App\Support\SalesRevenueCalculator;

use Tests\TestCase;



final class SalesRevenuePartaiFilterTest extends TestCase

{

    public function test_apply_partai_filter_skips_empty_and_matches_container_partai(): void

    {

        $empty = Barang::query();

        $sqlBefore = $empty->toSql();

        SalesRevenueCalculator::applyPartaiFilter($empty, null);

        SalesRevenueCalculator::applyPartaiFilter($empty, '');

        $this->assertSame($sqlBefore, $empty->toSql());



        $filtered = Barang::query();

        SalesRevenueCalculator::applyPartaiFilter($filtered, 'GZ-01');

        $this->assertStringContainsString('partai', $filtered->toSql());



        $unloaded = Barang::query();

        SalesRevenueCalculator::applyPartaiFilter($unloaded, 'belum_di_muat');

        $this->assertStringContainsString('container_barangs', $unloaded->toSql());

    }



    public function test_filter_rows_by_partai_label_keeps_only_matching_displayed_partai(): void

    {

        $rows = [

            ['barang_id' => 1, 'partai' => '1054', 'marking' => 'DEUCY'],

            ['barang_id' => 2, 'partai' => '1074', 'marking' => 'DEUCY'],

            ['barang_id' => 3, 'partai' => null, 'marking' => 'DEUCY'],

            ['barang_id' => 4, 'partai' => '1054', 'marking' => 'OTHER'],

        ];



        $filtered = SalesRevenueCalculator::filterRowsByPartaiLabel($rows, '1054');

        $this->assertCount(2, $filtered);

        $this->assertSame([1, 4], array_column($filtered, 'barang_id'));



        $unloaded = SalesRevenueCalculator::filterRowsByPartaiLabel($rows, 'belum_di_muat');

        $this->assertCount(1, $unloaded);

        $this->assertSame(3, $unloaded[0]['barang_id']);



        $this->assertSame($rows, SalesRevenueCalculator::filterRowsByPartaiLabel($rows, null));

        $this->assertSame($rows, SalesRevenueCalculator::filterRowsByPartaiLabel($rows, ''));

    }



    public function test_dedupe_rows_by_barang_id_keeps_first_occurrence(): void

    {

        $rows = [

            ['barang_id' => 10, 'marking' => 'A'],

            ['barang_id' => 10, 'marking' => 'A-dup'],

            ['barang_id' => 11, 'marking' => 'B'],

        ];



        $unique = SalesRevenueCalculator::dedupeRowsByBarangId($rows);

        $this->assertCount(2, $unique);

        $this->assertSame('A', $unique[0]['marking']);

        $this->assertSame('B', $unique[1]['marking']);

    }

}


