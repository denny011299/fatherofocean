<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Container;
use App\Support\InvoicePdfData;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class InvoicePdfGroupedTest extends TestCase
{
    public function test_for_container_grouped_splits_by_marking_with_sheet_index(): void
    {
        $container = new Container([
            'partai' => 'P-001',
        ]);

        $barangA1 = new Barang(['no_index' => '1001', 'marking' => 'ABC01', 'total_cbm' => 1]);
        $barangA2 = new Barang(['no_index' => '1002', 'marking' => 'ABC01', 'total_cbm' => 2]);
        $barangB = new Barang(['no_index' => '2001', 'marking' => 'XYZ02', 'total_cbm' => 3]);

        $container->setRelation('barangs', new Collection([$barangA1, $barangA2, $barangB]));
        $container->setRelation('port', null);

        $payload = InvoicePdfData::forContainerGrouped($container);

        $this->assertSame('P-001', $payload['partai']);
        $this->assertCount(2, $payload['sections']);

        $this->assertSame(1, $payload['sections'][0]['marking_index']);
        $this->assertSame(2, $payload['sections'][0]['marking_total']);
        $this->assertSame('ABC01', $payload['sections'][0]['markingLabel']);
        $this->assertStringContainsString('ABC01', (string) $payload['sections'][0]['sheet_title']);

        $this->assertSame(2, $payload['sections'][1]['marking_index']);
        $this->assertSame(2, $payload['sections'][1]['marking_total']);
        $this->assertSame('XYZ02', $payload['sections'][1]['markingLabel']);
    }

    public function test_grouped_invoice_blade_renders_sheet_banners(): void
    {
        $container = new Container(['partai' => 'P-002']);
        $barangA = new Barang(['no_index' => '3001', 'marking' => 'M1', 'total_cbm' => 1, 'ctns' => 1]);
        $barangB = new Barang(['no_index' => '3002', 'marking' => 'M2', 'total_cbm' => 1, 'ctns' => 1]);
        $container->setRelation('barangs', new Collection([$barangA, $barangB]));
        $container->setRelation('port', null);

        $payload = InvoicePdfData::forContainerGrouped($container);
        $html = view('exports.invoice-pdf', $payload)->render();

        $this->assertSame(2, substr_count($html, 'sheet-banner'));
        $this->assertStringContainsString('M1', $html);
        $this->assertStringContainsString('M2', $html);
    }
}
