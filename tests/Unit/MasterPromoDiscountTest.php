<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\MasterPromo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MasterPromoDiscountTest extends TestCase
{
    #[DataProvider('masterPromoDiscountProvider')]
    public function test_master_promo_calculates_percent_and_fixed_discount(
        string $type,
        ?float $persen,
        ?float $amountRp,
        float $beforeDiscount,
        float $expected,
    ): void {
        $promo = new MasterPromo([
            'type' => $type,
            'persen' => $persen,
            'amount_rp' => $amountRp,
        ]);

        $this->assertSame($expected, $promo->calculateDiscount($beforeDiscount));
    }

    public static function masterPromoDiscountProvider(): array
    {
        return [
            'percent 10 on 1_000_000' => ['percent', 10.0, null, 1_000_000.0, 100_000.0],
            'fixed 250_000 on 1_000_000' => ['fixed_rp', null, 250_000.0, 1_000_000.0, 250_000.0],
            'fixed capped by total' => ['fixed_rp', null, 500_000.0, 300_000.0, 300_000.0],
            'zero percent' => ['percent', 0.0, null, 1_000_000.0, 0.0],
        ];
    }

    public function test_invoice_recalculate_uses_fixed_rp_snapshot(): void
    {
        $invoice = new Invoice([
            'subtotal' => 800_000,
            'insurance_total' => 100_000,
            'extra_charges' => 100_000,
            'promo_type' => MasterPromo::TYPE_FIXED_RP,
            'promo_amount_rp' => 150_000,
            'promo_persen' => null,
            'claim_deduction_total' => 0,
        ]);

        $discount = $invoice->calculatePromoDiscount(1_000_000.0);

        $this->assertSame(150_000.0, $discount);
    }

    public function test_invoice_recalculate_uses_percent_snapshot(): void
    {
        $invoice = new Invoice([
            'promo_type' => MasterPromo::TYPE_PERCENT,
            'promo_persen' => 5,
            'promo_amount_rp' => null,
        ]);

        $discount = $invoice->calculatePromoDiscount(2_000_000.0);

        $this->assertSame(100_000.0, $discount);
    }

    public function test_master_promo_snapshot_for_invoice(): void
    {
        $percentPromo = new MasterPromo([
            'type' => MasterPromo::TYPE_PERCENT,
            'persen' => 12.5,
            'amount_rp' => null,
        ]);

        $this->assertSame([
            'type' => MasterPromo::TYPE_PERCENT,
            'persen' => 12.5,
            'amount_rp' => null,
        ], $percentPromo->snapshotForInvoice());

        $fixedPromo = new MasterPromo([
            'type' => MasterPromo::TYPE_FIXED_RP,
            'persen' => null,
            'amount_rp' => 75_000,
        ]);

        $this->assertSame([
            'type' => MasterPromo::TYPE_FIXED_RP,
            'persen' => null,
            'amount_rp' => 75_000.0,
        ], $fixedPromo->snapshotForInvoice());
    }
}
