<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Exports\FinanceInvoiceListSpreadsheetBuilder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Tests\TestCase;

final class FinanceInvoiceListSpreadsheetBuilderTest extends TestCase
{
    public function test_multi_shipment_invoice_merges_and_uses_sum_formulas(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        (new FinanceInvoiceListSpreadsheetBuilder())->build($sheet, [[
            'invoice_no' => 'INV-123+234',
            'marking_label' => 'EK/ON/ELOK88',
            'customer_name' => 'Simon',
            'marketing_label' => 'David',
            'partai_label' => '1074',
            'loading_date' => Carbon::parse('2026-07-15'),
            'pengiriman' => '1 invoice · 2 pengiriman',
            'due_date_label' => '6 Jul 2026',
            'tanggal_lunas' => null,
            'tanggal_lunas_label' => '—',
            'status_label' => 'Belum Lunas',
            'grand_total' => 19112500,
            'shipments' => [
                ['no_index' => '123usal', 'total' => 10000000.0],
                ['no_index' => '234hjjj', 'total' => 9112500.0],
            ],
            'payments' => [
                ['amount' => 10000000.0, 'paid_at' => Carbon::parse('2026-07-10')],
                ['amount' => 9000000.0, 'paid_at' => Carbon::parse('2026-07-12')],
            ],
        ]]);

        $this->assertSame('No Index', $sheet->getCell('B1')->getValue());
        $this->assertSame('Grand Total (Rp)', $sheet->getCell('J1')->getValue());
        $this->assertSame('123usal', $sheet->getCell('B2')->getValue());
        $this->assertSame('234hjjj', $sheet->getCell('B3')->getValue());
        $this->assertSame(10000000.0, (float) $sheet->getCell('I2')->getValue());
        $this->assertSame(9112500.0, (float) $sheet->getCell('I3')->getValue());
        $this->assertSame('=SUM(I2:I3)', $sheet->getCell('J2')->getValue());
        $this->assertSame('=J2-SUM(K2:K3)', $sheet->getCell('M2')->getValue());
        $this->assertSame(10000000.0, (float) $sheet->getCell('K2')->getValue());
        $this->assertSame(9000000.0, (float) $sheet->getCell('K3')->getValue());

        $merges = array_keys($sheet->getMergeCells());
        $this->assertContains('A2:A3', $merges);
        $this->assertContains('C2:C3', $merges);
        $this->assertContains('J2:J3', $merges);
        $this->assertContains('M2:M3', $merges);
        $this->assertNotContains('B2:B3', $merges);
        $this->assertNotContains('I2:I3', $merges);
        $this->assertNotContains('K2:K3', $merges);

        $headerFill = $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB();
        $this->assertSame('0A2240', strtoupper($headerFill));

        $bodyFill = $sheet->getStyle('A2')->getFill()->getStartColor()->getRGB();
        $this->assertSame('FFFFFF', strtoupper($bodyFill));
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $sheet->getStyle('A2')->getAlignment()->getHorizontal(),
        );
        $this->assertSame(
            Alignment::VERTICAL_CENTER,
            $sheet->getStyle('B2')->getAlignment()->getVertical(),
        );
    }

    public function test_payment_heavy_invoice_adds_extra_rows_without_fake_indexes(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        (new FinanceInvoiceListSpreadsheetBuilder())->build($sheet, [[
            'invoice_no' => 'INV-1',
            'marking_label' => 'MK',
            'customer_name' => 'Cust',
            'marketing_label' => 'Sales',
            'partai_label' => '1',
            'loading_date' => null,
            'pengiriman' => '1 invoice · 1 pengiriman',
            'due_date_label' => '1 Jan 2026',
            'tanggal_lunas' => null,
            'tanggal_lunas_label' => '—',
            'status_label' => 'Belum Lunas',
            'grand_total' => 3000,
            'shipments' => [
                ['no_index' => 'IDX-1', 'total' => 3000.0],
            ],
            'payments' => [
                ['amount' => 1000.0, 'paid_at' => Carbon::parse('2026-01-01')],
                ['amount' => 1000.0, 'paid_at' => Carbon::parse('2026-01-02')],
                ['amount' => 500.0, 'paid_at' => Carbon::parse('2026-01-03')],
            ],
        ]]);

        $this->assertSame('IDX-1', $sheet->getCell('B2')->getValue());
        $this->assertSame('', (string) $sheet->getCell('B3')->getValue());
        $this->assertSame('', (string) $sheet->getCell('B4')->getValue());
        $this->assertSame('=SUM(I2:I4)', $sheet->getCell('J2')->getValue());
        $this->assertSame('=J2-SUM(K2:K4)', $sheet->getCell('M2')->getValue());
        $this->assertContains('A2:A4', array_keys($sheet->getMergeCells()));
        $this->assertSame(0.0, (float) $sheet->getCell('I3')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('I4')->getValue());
    }

    public function test_unpaid_invoice_shows_zero_not_blank_for_amounts(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        (new FinanceInvoiceListSpreadsheetBuilder())->build($sheet, [[
            'invoice_no' => 'INV-2',
            'marking_label' => 'MK',
            'customer_name' => 'Cust',
            'marketing_label' => 'Sales',
            'partai_label' => '1',
            'loading_date' => null,
            'pengiriman' => '1 invoice · 2 pengiriman',
            'due_date_label' => '1 Jan 2026',
            'tanggal_lunas' => null,
            'tanggal_lunas_label' => '—',
            'status_label' => 'Belum Lunas',
            'grand_total' => 5000,
            'shipments' => [
                ['no_index' => 'A', 'total' => 3000.0],
                ['no_index' => 'B', 'total' => 2000.0],
            ],
            'payments' => [],
        ]]);

        $this->assertSame(0.0, (float) $sheet->getCell('K2')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('K3')->getValue());
        $this->assertSame('—', (string) $sheet->getCell('L2')->getValue());
    }

    public function test_date_format_includes_year(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        (new FinanceInvoiceListSpreadsheetBuilder())->build($sheet, [[
            'invoice_no' => 'INV-3',
            'marking_label' => 'MK',
            'customer_name' => 'Cust',
            'marketing_label' => 'Sales',
            'partai_label' => '1',
            'loading_date' => Carbon::parse('2026-06-01'),
            'pengiriman' => '1 invoice · 1 pengiriman',
            'due_date_label' => '1 Jan 2026',
            'tanggal_lunas' => Carbon::parse('2026-07-10'),
            'tanggal_lunas_label' => '10 Jul 2026',
            'status_label' => 'Lunas',
            'grand_total' => 1000,
            'shipments' => [
                ['no_index' => 'A', 'total' => 1000.0],
            ],
            'payments' => [
                ['amount' => 1000.0, 'paid_at' => Carbon::parse('2026-07-10')],
            ],
        ]]);

        $this->assertSame('dd-mmm-yyyy', $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd-mmm-yyyy', $sheet->getStyle('L2')->getNumberFormat()->getFormatCode());
        $this->assertSame('dd-mmm-yyyy', $sheet->getStyle('O2')->getNumberFormat()->getFormatCode());
    }
}
