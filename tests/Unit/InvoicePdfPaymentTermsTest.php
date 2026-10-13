<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\InvoicePdfData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

final class InvoicePdfPaymentTermsTest extends TestCase
{
    public function test_payment_terms_lunas_when_due_date_same_as_invoice_date(): void
    {
        $issued = Carbon::parse('2026-04-25');

        $terms = $this->resolvePaymentTerms(
            new Invoice([
                'grand_total' => 1_000_000,
                'created_at' => $issued,
                'due_date' => $issued->copy(),
            ]),
            collect(),
        );

        $this->assertSame('Lunas kirim', $terms);
    }

    public function test_payment_terms_tempo_includes_due_date_from_invoice(): void
    {
        $issued = Carbon::parse('2026-04-25');
        $due = Carbon::parse('2026-05-02');

        $terms = $this->resolvePaymentTerms(
            new Invoice([
                'grand_total' => 1_000_000,
                'created_at' => $issued,
                'due_date' => $due,
            ]),
            collect(),
        );

        $this->assertSame('Tempo (jatuh tempo 2 May 2026)', $terms);
    }

    public function test_payment_terms_lunas_when_marking_due_days_zero_even_if_invoice_due_date_exists(): void
    {
        $barang = new Barang(['marking' => 'MARK-CASH']);
        $barang->setAttribute('_invoice_due_days', 0);

        $terms = $this->resolvePaymentTerms(
            new Invoice([
                'grand_total' => 1_000_000,
                'created_at' => Carbon::parse('2026-07-06'),
                'due_date' => Carbon::parse('2026-07-16'),
            ]),
            collect([$barang]),
        );

        $this->assertSame('Lunas kirim', $terms);
    }

    public function test_payment_terms_tempo_when_marking_due_days_more_than_zero(): void
    {
        $barang = new Barang(['marking' => 'MARK-TEMPO']);
        $barang->setAttribute('_invoice_due_days', 10);

        $terms = $this->resolvePaymentTerms(
            new Invoice([
                'grand_total' => 1_000_000,
                'created_at' => Carbon::parse('2026-07-06'),
                'due_date' => Carbon::parse('2026-07-16'),
            ]),
            collect([$barang]),
        );

        $this->assertSame('Tempo (jatuh tempo 16 Jul 2026)', $terms);
    }

    public function test_payment_terms_lunas_when_invoice_fully_paid(): void
    {
        $invoice = new Invoice([
            'grand_total' => 500_000,
            'due_date' => today()->addDays(7),
        ]);
        $invoice->setRelation('payments', new Collection([
            new Payment(['amount' => 500_000, 'status' => 'approved']),
        ]));

        $terms = $this->resolvePaymentTerms(
            $invoice,
            collect([new Barang(['marking' => 'MARK-A'])]),
        );

        $this->assertSame('Lunas', $terms);
    }

    public function test_payment_terms_never_returns_bongkar_placeholder(): void
    {
        $terms = $this->resolvePaymentTerms(
            new Invoice([
                'grand_total' => 1_000_000,
                'created_at' => Carbon::parse('2026-04-25'),
                'due_date' => Carbon::parse('2026-05-02'),
            ]),
            collect(),
        );

        $this->assertNotSame('Status bongkar', $terms);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private function resolvePaymentTerms(Invoice $invoice, Collection $barangs): string
    {
        $method = new ReflectionMethod(InvoicePdfData::class, 'resolvePaymentTerms');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $invoice, $barangs);
    }
}
