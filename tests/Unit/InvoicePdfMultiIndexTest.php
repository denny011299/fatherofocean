<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\InvoicePdfData;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class InvoicePdfMultiIndexTest extends TestCase
{
    public function test_for_invoice_uses_one_frame_for_multiple_indexes(): void
    {
        $invoice = new Invoice([
            'invoice_no' => 'INV-1105.1+1105.02',
            'insurance_total' => 0,
            'extra_charges' => 0,
            'promo_discount' => 0,
            'claim_deduction_total' => 0,
            'grand_total' => 1_000_000,
        ]);
        $invoice->id = 1;

        $barangA = new Barang([
            'no_index' => '1105.1',
            'no_po' => 'PO-A',
            'marking' => 'DBS/JOHS/SEA',
            'total_cbm' => 0.164,
            'ctns' => 1,
            'price_per_cbm' => 4_500_000,
        ]);
        $barangB = new Barang([
            'no_index' => '1105.02',
            'no_po' => 'PO-B',
            'marking' => 'DBS/JOHS/SEA',
            'total_cbm' => 0.092,
            'ctns' => 1,
            'price_per_cbm' => 4_500_000,
        ]);

        $barangA->setRelation('products', collect());
        $barangA->setRelation('additionalPrices', collect());
        $barangB->setRelation('products', collect());
        $barangB->setRelation('additionalPrices', collect());

        $itemA = new InvoiceItem(['line_total' => 738_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.164]);
        $itemA->setRelation('barang', $barangA);
        $itemB = new InvoiceItem(['line_total' => 450_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.092]);
        $itemB->setRelation('barang', $barangB);

        $invoice->setRelation('items', new Collection([$itemA, $itemB]));
        $invoice->setRelation('customer', null);

        $payload = InvoicePdfData::forInvoice($invoice);

        $this->assertTrue($payload['is_multi_index']);
        $this->assertTrue($payload['show_grand_footer']);
        $this->assertSame(2, $payload['shipment_count']);
        $this->assertCount(1, $payload['sections']);
        $this->assertFalse($payload['sections'][0]['show_payment_footer']);
        $this->assertTrue($payload['sections'][0]['show_letterhead']);
        $this->assertStringContainsString('1105.1', (string) $payload['sections'][0]['noIndexLabel']);
        $this->assertStringContainsString('1105.02', (string) $payload['sections'][0]['noIndexLabel']);
    }

    public function test_multi_index_invoice_blade_is_one_table_frame(): void
    {
        $invoice = new Invoice([
            'invoice_no' => 'INV-1105.1+1105.02',
            'insurance_total' => 0,
            'extra_charges' => 0,
            'promo_discount' => 0,
            'claim_deduction_total' => 0,
            'grand_total' => 1_188_000,
        ]);

        $barangA = new Barang(['no_index' => '1105.1', 'marking' => 'DBS', 'total_cbm' => 0.164, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangB = new Barang(['no_index' => '1105.02', 'marking' => 'DBS', 'total_cbm' => 0.092, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangA->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Box A',
                'ctns' => 1,
                'sort_order' => 1,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 20,
                'gross_weight_kg' => 10,
            ]),
        ]));
        $barangB->setRelation('products', collect([
            new BarangProduct([
                'product_name' => 'Box B',
                'ctns' => 1,
                'sort_order' => 1,
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 30,
                'gross_weight_kg' => 20,
            ]),
        ]));
        $barangA->setRelation('additionalPrices', collect());
        $barangB->setRelation('additionalPrices', collect());

        $itemA = new InvoiceItem(['line_total' => 738_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.164]);
        $itemA->setRelation('barang', $barangA);
        $itemB = new InvoiceItem(['line_total' => 450_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.092]);
        $itemB->setRelation('barang', $barangB);

        $invoice->setRelation('items', new Collection([$itemA, $itemB]));
        $invoice->setRelation('customer', null);

        $payload = InvoicePdfData::forInvoice($invoice);
        $html = view('exports.invoice-pdf', $payload)->render();

        $this->assertStringNotContainsString('Pengiriman 1 dari', $html);
        $this->assertStringNotContainsString('Pengiriman 2 dari', $html);
        $this->assertStringNotContainsString('index-section-gap', $html);
        $this->assertStringContainsString('grand-total-table', $html);
        $this->assertStringContainsString('ONG. KIRIM', $html);
        $this->assertSame(1, substr_count($html, 'class="header-top"'));
        $this->assertSame(1, substr_count($html, 'Payment Information'));
        $this->assertSame(1, substr_count($html, 'class="items-table'));
        $this->assertSame(0, substr_count($html, 'Subtotal pengiriman'));
        $this->assertStringContainsString('>1105.1</td>', $html);
        $this->assertStringContainsString('>1105.02</td>', $html);
        $this->assertStringContainsString('>1</td>', $html);
        $this->assertStringContainsString('>2</td>', $html);

        $rows = $payload['sections'][0]['tableRows'] ?? [];
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['ctn_no']);
        $this->assertSame('2', $rows[1]['ctn_no']);
    }

    public function test_table_rows_include_index_no_for_pdf_column(): void
    {
        $barang = new Barang([
            'no_index' => '1004.56',
            'marking' => 'OCEAN/LINK',
            'total_cbm' => 0.5,
            'ctns' => 1,
            'price_per_cbm' => 1_000_000,
        ]);
        $barang->setRelation('products', collect());
        $barang->setRelation('additionalPrices', collect());

        $item = new InvoiceItem(['line_total' => 500_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.5]);
        $item->setRelation('barang', $barang);

        $invoice = new Invoice([
            'invoice_no' => 'INV-1004.56',
            'insurance_total' => 0,
            'extra_charges' => 0,
            'promo_discount' => 0,
            'claim_deduction_total' => 0,
            'grand_total' => 500_000,
        ]);
        $invoice->setRelation('items', new Collection([$item]));
        $invoice->setRelation('customer', null);

        $rows = $invoice->items->isNotEmpty()
            ? (InvoicePdfData::forInvoice($invoice)['sections'][0]['tableRows'] ?? [])
            : [];

        $this->assertNotEmpty($rows);
        $this->assertSame('1004.56', $rows[0]['index_no'] ?? null);

        $html = view('exports.invoice-pdf', InvoicePdfData::forInvoice($invoice))->render();
        $this->assertStringContainsString('>1004.56</td>', $html);
    }

    public function test_api_shipment_payload_groups_sections_for_finance_ui(): void
    {
        $invoice = new Invoice([
            'invoice_no' => 'INV-1105.1+1105.02',
            'insurance_total' => 0,
            'extra_charges' => 0,
            'promo_discount' => 0,
            'claim_deduction_total' => 0,
            'grand_total' => 1_188_000,
        ]);

        $barangA = new Barang(['no_index' => '1105.1', 'marking' => 'DBS', 'total_cbm' => 0.164, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangB = new Barang(['no_index' => '1105.02', 'marking' => 'DBS', 'total_cbm' => 0.092, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangA->setRelation('products', collect());
        $barangA->setRelation('additionalPrices', collect());
        $barangB->setRelation('products', collect());
        $barangB->setRelation('additionalPrices', collect());

        $itemA = new InvoiceItem(['line_total' => 738_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.164]);
        $itemA->setRelation('barang', $barangA);
        $itemB = new InvoiceItem(['line_total' => 450_000, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.092]);
        $itemB->setRelation('barang', $barangB);

        $invoice->setRelation('items', new Collection([$itemA, $itemB]));
        $invoice->setRelation('customer', null);

        $payload = InvoicePdfData::apiShipmentPayload($invoice);

        $this->assertTrue($payload['is_multi_shipment']);
        $this->assertSame(2, $payload['shipment_count']);
        $this->assertCount(2, $payload['sections']);
        $this->assertSame('1105.1', $payload['sections'][0]['no_index']);
        $this->assertSame('1105.02', $payload['sections'][1]['no_index']);
        $this->assertNotEmpty($payload['consolidated_summary']);
    }

    public function test_invoice_distinct_shipment_count_matches_pdf_sections(): void
    {
        $invoice = new Invoice(['invoice_no' => 'INV-1105.1+1105.02']);
        $invoice->id = 1;

        $barangA = new Barang(['no_index' => '1105.1']);
        $barangB = new Barang(['no_index' => '1105.02']);
        $barangC = new Barang(['no_index' => '1105.02']);

        $itemA = new InvoiceItem();
        $itemA->setRelation('barang', $barangA);
        $itemB = new InvoiceItem();
        $itemB->setRelation('barang', $barangB);
        $itemC = new InvoiceItem();
        $itemC->setRelation('barang', $barangC);

        $invoice->setRelation('items', new Collection([$itemA, $itemB, $itemC]));

        $this->assertSame(2, $invoice->distinctShipmentCount());
        $this->assertTrue($invoice->isMultiShipment());
        $this->assertNotNull($invoice->consolidatedSummary());
    }

    public function test_multi_index_pdf_shows_claim_without_reducing_grand_total(): void
    {
        $invoice = new Invoice([
            'invoice_no' => 'INV-CLAIM',
            'insurance_total' => 0,
            'extra_charges' => 0,
            'promo_discount' => 0,
            'claim_deduction_total' => 5_000_000,
            'grand_total' => 91_139_400,
        ]);
        $invoice->id = 1;

        $barangA = new Barang(['no_index' => '1105.1', 'marking' => 'EK/DN/AGTRADE', 'total_cbm' => 0.164, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangB = new Barang(['no_index' => '1105.02', 'marking' => 'EK/DN/AGTRADE', 'total_cbm' => 0.092, 'ctns' => 1, 'price_per_cbm' => 4_500_000]);
        $barangA->setRelation('products', collect());
        $barangA->setRelation('additionalPrices', collect());
        $barangB->setRelation('products', collect());
        $barangB->setRelation('additionalPrices', collect());

        $itemA = new InvoiceItem(['line_total' => 48_069_700, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.164]);
        $itemA->setRelation('barang', $barangA);
        $itemB = new InvoiceItem(['line_total' => 48_069_700, 'insurance' => 0, 'extra' => 0, 'cbm' => 0.092]);
        $itemB->setRelation('barang', $barangB);

        $invoice->setRelation('items', new Collection([$itemA, $itemB]));
        $invoice->setRelation('customer', null);
        $invoice->setRelation('claims', new Collection([
            (object) ['status' => 'approved', 'amount' => 5_000_000],
        ]));

        $payload = InvoicePdfData::forInvoice($invoice);

        $this->assertSame('Rp 5.000.000', $payload['grand_summary']['claim_total']);
        $this->assertSame('Rp 96.139.400', $payload['grand_summary']['total']);
        $this->assertSame('Rp 96.139.400', $payload['totalLabel']);

        $html = view('exports.invoice-pdf', $payload)->render();
        $this->assertStringContainsString('Rp 5.000.000', $html);
        $this->assertStringContainsString('Klaim Akan dikembalikan setelah pelunasan', $html);
        $this->assertStringNotContainsString('Total Sebelum Potong Klaim', $html);
    }
}
