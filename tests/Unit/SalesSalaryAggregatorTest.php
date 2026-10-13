<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\User;
use App\Support\SalesRevenueCalculator;
use App\Support\SalesSalaryAggregator;
use App\Support\ShipmentEarningsSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesSalaryAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
    }

    public function test_monthly_payout_uses_tanggal_masuk_not_settled_month(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $owner = User::factory()->create();
        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'PAYOUT01',
            'customer_name' => 'Test',
        ]);

        $barangJune = $this->createBarang($marking, 'IDX-JUN', '2026-06-15');
        $barangMay = $this->createBarang($marking, 'IDX-MAY', '2026-05-10');

        $this->attachPaidInvoice($barangJune);
        $this->attachPaidInvoice($barangMay);

        ShipmentEarningsSettlement::syncForBarang($barangJune);
        ShipmentEarningsSettlement::syncForBarang($barangMay);

        SalesCommission::query()
            ->where('barang_id', $barangMay->id)
            ->update(['settled_at' => '2026-06-20 10:00:00']);

        $period = '2026-06';
        $range = SalesSalaryAggregator::periodDateRange($period);

        $expectedTotal = 0.0;
        $expectedCount = 0;
        foreach (SalesRevenueCalculator::commissionRows(null, null, $range['date_from'], $range['date_to']) as $row) {
            if ((int) $row['sales_id'] !== (int) $sales->id || ! ($row['is_fixed'] ?? false)) {
                continue;
            }
            $expectedCount++;
            $expectedTotal += (float) ($row['confirmed_amount'] ?? $row['estimated_amount']);
        }

        $matrix = SalesSalaryAggregator::monthlyPayoutMatrix($period, (int) $sales->id);
        $payoutRow = $matrix->first();

        $this->assertNotNull($payoutRow);
        $this->assertSame(1, $payoutRow->item_count);
        $this->assertSame(round($expectedTotal, 2), $payoutRow->total_amount);
        $this->assertSame($expectedCount, $payoutRow->item_count);

        $detail = SalesSalaryAggregator::periodDetail((int) $sales->id, $period);
        $this->assertCount(1, $detail['items']);
        $this->assertSame($payoutRow->total_amount, $detail['total_amount']);
        $this->assertSame('IDX-JUN', $detail['items'][0]['no_index']);
    }

    private function createBarang(Marking $marking, string $noIndex, string $tanggalMasuk): Barang
    {
        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => $marking->code,
            'price_per_cbm' => 1_000_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'tanggal_masuk' => $tanggalMasuk,
            'created_by' => $marking->user_id,
        ]);
    }

    private function attachPaidInvoice(Barang $barang): void
    {
        $now = now();
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV-'.$barang->no_index,
            'user_id' => $barang->created_by,
            'price_per_cbm' => 1_000_000,
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $barang->id,
            'cbm' => 1,
            'price_per_cbm' => 1_000_000,
            'insurance' => 0,
            'extra' => 0,
            'line_total' => 1_000_000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
