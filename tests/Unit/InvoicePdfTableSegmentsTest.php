<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InvoicePdfTableSegments;
use Tests\TestCase;

final class InvoicePdfTableSegmentsTest extends TestCase
{
    public function test_fills_page_then_starts_new_page(): void
    {
        $rows = $this->rowsForGroup('0206.116', 'kapasitor', 30);

        $pages = InvoicePdfTableSegments::build($rows, hasLetterheadAboveTable: false);

        $this->assertCount(2, $pages);
        $this->assertCount(1, $pages[0]);
        $this->assertCount(22, $pages[0][0]);
        $this->assertCount(1, $pages[1]);
        $this->assertCount(8, $pages[1][0]);
        $this->assertSame('0206.116', $pages[1][0][0]['index_no']);
    }

    public function test_letterhead_page_keeps_table_on_same_page_then_full_pages(): void
    {
        $rows = $this->rowsForGroup('0206.116', 'kapasitor', 30);

        $pages = InvoicePdfTableSegments::build($rows, hasLetterheadAboveTable: true);

        $this->assertCount(2, $pages);
        $this->assertCount(10, $pages[0][0]);
        $this->assertCount(20, $pages[1][0]);
    }

    public function test_multiple_indexes_on_same_page_are_separate_merge_groups(): void
    {
        $rows = array_merge(
            $this->rowsForGroup('0806.02', 'voice coil tweeter', 1),
            $this->rowsForGroup('0806.4', 'kabel', 1),
            $this->rowsForGroup('1106.02', 'Sparepart Audio', 1),
        );

        $pages = InvoicePdfTableSegments::build($rows, hasLetterheadAboveTable: false);

        $this->assertCount(1, $pages);
        $this->assertCount(3, $pages[0]);
        $this->assertSame('0806.02', $pages[0][0][0]['index_no']);
        $this->assertSame('0806.4', $pages[0][1][0]['index_no']);
        $this->assertSame('1106.02', $pages[0][2][0]['index_no']);
    }

    /** @return list<array<string, mixed>> */
    private function rowsForGroup(string $indexNo, string $description, int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'show_merge' => $i === 1,
                'index_no' => $indexNo,
                'description' => $description,
                'coly' => '1',
                'ctn_no' => (string) (160 + $i),
                'length' => '45',
                'width' => '35',
                'height' => '32',
                'volume' => '0,050',
                'unit_weight' => '18',
                'total_weight' => '18',
            ];
        }

        return $rows;
    }
}
