<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Tests\TestCase;

final class InvoiceNumberFormatTest extends TestCase
{
    public function test_normalize_single_shipping_index(): void
    {
        $barang = new Barang(['no_index' => 'BRG-2026-0001']);

        $indexes = Invoice::normalizeShippingIndexes([$barang]);

        $this->assertSame(['BRG-2026-0001'], $indexes->all());
    }

    public function test_normalize_multiple_indexes_sorted_and_unique(): void
    {
        $indexes = Invoice::normalizeShippingIndexes([
            'BRG-2026-0003',
            'BRG-2026-0001',
            'BRG-2026-0003',
        ]);

        $this->assertSame(['BRG-2026-0001', 'BRG-2026-0003'], $indexes->all());
    }

    public function test_normalize_filters_empty_indexes(): void
    {
        $indexes = Invoice::normalizeShippingIndexes(['', '  ', null]);

        $this->assertTrue($indexes->isEmpty());
    }

    public function test_normalize_multiple_barang_indexes_sorted(): void
    {
        $barangA = new Barang(['no_index' => '1105.1']);
        $barangB = new Barang(['no_index' => '1105.02']);

        $indexes = Invoice::normalizeShippingIndexes([$barangA, $barangB]);

        $this->assertSame(['1105.02', '1105.1'], $indexes->all());
    }

    public function test_format_invoice_number_joins_multiple_indexes_when_short(): void
    {
        $indexes = Invoice::normalizeShippingIndexes(['1105.1', '1105.02']);

        $this->assertSame('INV-1105.02+1105.1', Invoice::formatInvoiceNumber($indexes));
    }

    public function test_format_invoice_number_includes_marking_before_indexes(): void
    {
        $indexes = Invoice::normalizeShippingIndexes(['GZ-EK-0000060', 'GZ-EK-0000057']);

        $this->assertSame(
            'INV-EK/ON/SARA - GZ-EK-0000057+GZ-EK-0000060',
            Invoice::formatInvoiceNumber($indexes, 'EK/ON/SARA'),
        );
    }

    public function test_format_invoice_number_single_index_with_marking(): void
    {
        $indexes = Invoice::normalizeShippingIndexes(['GZ-EK-0000057']);

        $this->assertSame(
            'INV-EK/ON/SARA - GZ-EK-0000057',
            Invoice::formatInvoiceNumber($indexes, 'EK/ON/SARA'),
        );
    }

    public function test_format_invoice_number_strips_marking_whitespace(): void
    {
        $indexes = Invoice::normalizeShippingIndexes(['1105.1']);

        $this->assertSame(
            'INV-EK/ON/SARA - 1105.1',
            Invoice::formatInvoiceNumber($indexes, ' EK / ON / SARA '),
        );
    }

    public function test_format_invoice_number_joins_thirteen_production_indexes(): void
    {
        $indexes = Invoice::normalizeShippingIndexes([
            '11.05.01',
            '11.05.1',
            '1105.01.01',
            '1105.01.1',
            '1105.02',
            '1105.03',
            '1105.1',
            '1105.3',
            '1205.1',
            '1305.01',
            '1305.1',
            '1505.01',
            '1805.01',
        ]);

        $this->assertCount(13, $indexes);

        $number = Invoice::formatInvoiceNumber($indexes);

        $this->assertSame(
            'INV-11.05.01+11.05.1+1105.01.01+1105.01.1+1105.02+1105.03+1105.1+1105.3+1205.1+1305.01+1305.1+1505.01+1805.01',
            $number,
        );
        $this->assertSame(109, strlen($number));
        $this->assertLessThanOrEqual(Invoice::INVOICE_NO_DISPLAY_MAX_LENGTH, strlen($number));
    }

    public function test_format_invoice_number_uses_compact_form_when_joined_exceeds_display_max(): void
    {
        $indexes = collect(range(1, 15))
            ->map(static fn (int $i): string => sprintf('SHIP-IDX-2026-%05d', $i))
            ->pipe(static fn ($c) => Invoice::normalizeShippingIndexes($c->all()));

        $fullJoined = 'INV-'.$indexes->implode('+');
        $this->assertGreaterThan(Invoice::INVOICE_NO_DISPLAY_MAX_LENGTH, strlen($fullJoined));

        $number = Invoice::formatInvoiceNumber($indexes);

        $this->assertLessThanOrEqual(Invoice::INVOICE_NO_DISPLAY_MAX_LENGTH, strlen($number));
        $this->assertSame('INV-SHIP-IDX-2026-00001+14pengiriman', $number);
    }

    public function test_format_invoice_number_compact_form_keeps_marking_prefix(): void
    {
        $indexes = collect(range(1, 15))
            ->map(static fn (int $i): string => sprintf('SHIP-IDX-2026-%05d', $i))
            ->pipe(static fn ($c) => Invoice::normalizeShippingIndexes($c->all()));

        $number = Invoice::formatInvoiceNumber($indexes, 'EK/ON/SARA');

        $this->assertLessThanOrEqual(Invoice::INVOICE_NO_DISPLAY_MAX_LENGTH, strlen($number));
        $this->assertSame('INV-EK/ON/SARA - SHIP-IDX-2026-00001+14pengiriman', $number);
    }

    public function test_resolve_marking_from_barang_sources(): void
    {
        $barang = new Barang([
            'no_index' => 'GZ-EK-0000057',
            'marking' => 'EK/ON/SARA',
        ]);

        $this->assertSame('EK/ON/SARA', Invoice::resolveMarkingFromShippingSources([$barang]));
    }

    public function test_display_invoice_no_rebuilds_legacy_stored_number(): void
    {
        $invoice = new Invoice(['invoice_no' => 'INV-GZ-EK-0000057+GZ-EK-0000060']);
        $invoice->setRelation('items', collect([
            tap(new InvoiceItem, static function (InvoiceItem $item): void {
                $item->setRelation('barang', new Barang([
                    'no_index' => 'GZ-EK-0000060',
                    'marking' => 'EK/ON/SARA',
                ]));
            }),
            tap(new InvoiceItem, static function (InvoiceItem $item): void {
                $item->setRelation('barang', new Barang([
                    'no_index' => 'GZ-EK-0000057',
                    'marking' => 'EK/ON/SARA',
                ]));
            }),
        ]));

        $this->assertSame(
            'INV-EK/ON/SARA - GZ-EK-0000057+GZ-EK-0000060',
            $invoice->displayInvoiceNo(),
        );
    }

    public function test_download_file_basename_sanitizes_marking_slashes(): void
    {
        $invoice = new Invoice(['invoice_no' => 'INV-EK/ON/SARA - GZ-EK-0000057']);

        $this->assertSame(
            'invoice-INV-EK-ON-SARA-GZ-EK-0000057.pdf',
            $invoice->downloadFileBasename('pdf'),
        );
    }
}
