<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SupervisorTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
    }

    public function test_supervisor_sees_only_team_markings_and_invoices(): void
    {
        $supervisor = User::factory()->create(['name' => 'Gladis Tanaya']);
        $supervisor->assignRole('supervisor');

        $subordinate = User::factory()->create(['name' => 'Subordinate Sales']);
        $subordinate->assignRole('sales');

        $otherSales = User::factory()->create(['name' => 'Other Sales']);
        $otherSales->assignRole('sales');

        $supervisor->subordinates()->attach($subordinate->id);

        $teamMarking = Marking::query()->create([
            'user_id' => $supervisor->id,
            'sales_id' => $subordinate->id,
            'code' => 'TEAM01',
            'customer_name' => 'Team Customer',
        ]);

        Marking::query()->create([
            'user_id' => $otherSales->id,
            'sales_id' => $otherSales->id,
            'code' => 'OTHER01',
            'customer_name' => 'Other Customer',
        ]);

        $this->actingAs($supervisor);

        $visibleCodes = Marking::visibleToUser($supervisor)->pluck('code')->all();
        $this->assertContains('TEAM01', $visibleCodes);
        $this->assertNotContains('OTHER01', $visibleCodes);

        $teamBarang = Barang::query()->create([
            'no_index' => 'IDX-TEAM',
            'marking' => $teamMarking->code,
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER01',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $barangQuery = Barang::query();
        Marking::restrictBarangsQuery($barangQuery, $supervisor);
        $this->assertSame(['IDX-TEAM'], $barangQuery->pluck('no_index')->all());

        $customer = User::factory()->create();
        $teamInvoice = Invoice::query()->create([
            'invoice_no' => 'INV-TEAM-1',
            'user_id' => $customer->id,
            'status' => 'sent',
            'grand_total' => 100_000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $teamInvoice->id,
            'barang_id' => $teamBarang->id,
            'cbm' => 1,
            'price_per_cbm' => 100_000,
            'insurance' => 0,
            'extra' => 0,
        ]);

        $otherBarang = Barang::query()->where('no_index', 'IDX-OTHER')->firstOrFail();
        $otherInvoice = Invoice::query()->create([
            'invoice_no' => 'INV-OTHER-1',
            'user_id' => $customer->id,
            'status' => 'sent',
            'grand_total' => 200_000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $otherInvoice->id,
            'barang_id' => $otherBarang->id,
            'cbm' => 1,
            'price_per_cbm' => 200_000,
            'insurance' => 0,
            'extra' => 0,
        ]);

        $invoiceQuery = Invoice::query();
        Marking::restrictInvoicesQuery($invoiceQuery, $supervisor);
        $this->assertSame(['INV-TEAM-1'], $invoiceQuery->pluck('invoice_no')->all());
    }

    public function test_supervisor_with_view_all_marking_permission_stays_team_scoped(): void
    {
        $supervisor = User::factory()->create(['name' => 'Daniel Supervisor']);
        $supervisor->assignRole('supervisor');
        $supervisor->givePermissionTo('view all marking');

        $subordinate = User::factory()->create(['name' => 'Team Sales']);
        $subordinate->assignRole('sales');

        $otherSales = User::factory()->create(['name' => 'Other Sales']);
        $otherSales->assignRole('sales');

        $supervisor->subordinates()->attach($subordinate->id);

        Marking::query()->create([
            'user_id' => $supervisor->id,
            'sales_id' => $subordinate->id,
            'code' => 'TEAM01',
            'customer_name' => 'Team Customer',
        ]);

        Marking::query()->create([
            'user_id' => $otherSales->id,
            'sales_id' => $otherSales->id,
            'code' => 'OTHER01',
            'customer_name' => 'Other Customer',
        ]);

        $this->actingAs($supervisor);

        $this->assertTrue(Marking::userHasSupervisorMarkingScope($supervisor));
        $this->assertFalse(Marking::userCanViewAll($supervisor));

        $visibleCodes = Marking::visibleToUser($supervisor)->pluck('code')->all();
        $this->assertContains('TEAM01', $visibleCodes);
        $this->assertNotContains('OTHER01', $visibleCodes);
    }

    public function test_finance_still_sees_all_markings(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('finance');

        Marking::query()->create([
            'code' => 'FIN-A',
            'customer_name' => 'A',
            'sales_id' => User::factory()->create()->id,
        ]);
        Marking::query()->create([
            'code' => 'FIN-B',
            'customer_name' => 'B',
            'sales_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($finance);

        $this->assertFalse(Marking::userHasTeamMarkingScope($finance));
        $this->assertSame(2, Marking::visibleToUser($finance)->count());
    }

    public function test_supervisor_commission_scope_excludes_other_sales_barang(): void
    {
        $supervisor = User::factory()->create(['name' => 'Gladis Tanaya']);
        $supervisor->assignRole('supervisor');

        $subordinate = User::factory()->create(['name' => 'Subordinate Sales']);
        $subordinate->assignRole('sales');

        $otherSales = User::factory()->create(['name' => 'Other Sales']);
        $otherSales->assignRole('sales');

        $supervisor->subordinates()->attach($subordinate->id);

        Marking::query()->create([
            'user_id' => $supervisor->id,
            'sales_id' => $subordinate->id,
            'code' => 'TEAM01',
            'customer_name' => 'Team Customer',
        ]);

        Marking::query()->create([
            'user_id' => $otherSales->id,
            'sales_id' => $otherSales->id,
            'code' => 'OTHER01',
            'customer_name' => 'Other Customer',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-TEAM',
            'marking' => 'TEAM01',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
            'tanggal_masuk' => now(),
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER01',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
            'tanggal_masuk' => now(),
        ]);

        $query = Barang::query();
        \App\Support\SalesRevenueCalculator::applyCommissionBarangScope($query, $supervisor);

        $this->assertSame(['IDX-TEAM'], $query->pluck('no_index')->all());
    }
}
