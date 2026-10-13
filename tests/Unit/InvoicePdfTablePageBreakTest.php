<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class InvoicePdfTablePageBreakTest extends TestCase
{
    public function test_full_page_then_new_table_repeats_index_and_item(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [
                'show_merge' => $i === 1,
                'index_no' => '0206.116',
                'description' => 'kapasitor, resistor daya',
                'coly' => '1',
                'ctn_no' => (string) (170 + $i),
                'length' => '45',
                'width' => '35',
                'height' => '32',
                'volume' => '0,050',
                'unit_weight' => '18',
                'total_weight' => '18',
            ];
        }

        $html = view('exports.invoice-pdf', [
            'sections' => [[
                'tableRows' => $rows,
                'tableSummary' => ['coly' => '30', 'volume' => '1,500', 'weight' => '540'],
                'shippingFooter' => ['keterangan' => '(CBM)', 'kubikasi_rate' => '-', 'kubikasi_amount' => '-', 'berat_rate' => '-', 'berat_amount' => '-', 'additional_rows' => []],
                'totalLabel' => 'Rp 0',
                'show_payment_footer' => false,
                'show_letterhead' => false,
            ]],
            'is_multi_index' => false,
            'show_grand_footer' => false,
        ])->render();

        $bodyHtml = explode('</style>', $html, 2)[1] ?? $html;
        $itemsTableHtml = explode('<table class="items-table items-summary-table', $bodyHtml, 2)[0];

        $this->assertSame(2, substr_count($itemsTableHtml, 'class="items-table items-table-page'));
        $this->assertSame(2, substr_count($itemsTableHtml, '<thead>'));
        $this->assertStringContainsString('rowspan="22"', $itemsTableHtml);
        $this->assertStringContainsString('rowspan="8"', $itemsTableHtml);
        $this->assertSame(2, substr_count($html, '>0206.116<'));
        $this->assertSame(2, substr_count($html, '>kapasitor, resistor daya<'));
        $this->assertMatchesRegularExpression('/items-table-page\s+items-table-page-first/', $itemsTableHtml);
        $this->assertStringContainsString('items-table-page-continue', $itemsTableHtml);
        // Tabel terakhir (rowspan 8) tanpa continue — summary boleh naik.
        $this->assertMatchesRegularExpression(
            '/<table class="items-table items-table-page\s+serif"[^>]*>[\s\S]*rowspan="8"/',
            $itemsTableHtml,
        );
    }

    public function test_small_rows_share_one_page_table(): void
    {
        $rows = [
            [
                'show_merge' => true,
                'index_no' => '0806.02',
                'description' => 'voice coil tweeter',
                'coly' => '1',
                'ctn_no' => '1',
                'length' => '50',
                'width' => '40',
                'height' => '39',
                'volume' => '0,078',
                'unit_weight' => '18',
                'total_weight' => '18',
            ],
            [
                'show_merge' => true,
                'index_no' => '0806.4',
                'description' => 'kabel',
                'coly' => '2',
                'ctn_no' => '2',
                'length' => '45',
                'width' => '35',
                'height' => '32',
                'volume' => '0,100',
                'unit_weight' => '20',
                'total_weight' => '40',
            ],
        ];

        $html = view('exports.invoice-pdf', [
            'sections' => [[
                'tableRows' => $rows,
                'tableSummary' => ['coly' => '3', 'volume' => '0,178', 'weight' => '58'],
                'shippingFooter' => ['keterangan' => '(CBM)', 'kubikasi_rate' => '-', 'kubikasi_amount' => '-', 'berat_rate' => '-', 'berat_amount' => '-', 'additional_rows' => []],
                'totalLabel' => 'Rp 0',
                'show_payment_footer' => false,
                'show_letterhead' => false,
            ]],
            'is_multi_index' => false,
            'show_grand_footer' => false,
        ])->render();

        $bodyHtml = explode('</style>', $html, 2)[1] ?? $html;
        $itemsTableHtml = explode('<table class="items-table items-summary-table', $bodyHtml, 2)[0];

        $this->assertMatchesRegularExpression('/class="items-table items-table-page\s+items-table-page-first/', $itemsTableHtml);
        $this->assertSame(1, substr_count($itemsTableHtml, '<thead>'));
        $this->assertStringContainsString('>voice coil tweeter<', $html);
        $this->assertStringContainsString('>kabel<', $html);
    }
}
