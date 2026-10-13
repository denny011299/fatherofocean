<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\CompanyRevenueSettings;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InvoiceAutoCustomerFromMarkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
    }

    public function test_ensure_customer_user_works_without_passcode(): void
    {
        $marking = Marking::query()->create([
            'code' => 'EK/ON/ARIASBY',
            'user_id' => null,
            'customer_name' => 'Alwin',
            'passcode' => null,
            'is_active' => true,
        ]);

        $user = $marking->ensureCustomerUser();

        $this->assertNotNull($user);
        $marking->refresh();
        $this->assertNotNull($marking->user_id);
        $this->assertSame('Alwin', $user->name);
        $this->assertTrue($user->hasRole('customer'));
    }

    public function test_invoice_created_when_marking_customer_was_missing(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');

        $sales = User::factory()->create(['name' => 'Alwin Sales']);
        $sales->assignRole('sales');

        $marking = Marking::query()->create([
            'code' => 'EK/ON/ARIASBY',
            'user_id' => null,
            'sales_id' => $sales->id,
            'customer_name' => 'Alwin',
            'passcode' => null,
            'is_active' => true,
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-ARIASBY-1',
            'marking' => $marking->code,
            'user_id' => null,
            'customer_name' => 'Alwin',
            'price_per_cbm' => 6_500_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => Barang::STATUS_STUFFING,
            'created_by' => $sales->id,
        ]);

        $invoice = $barang->recordInvoiceOnMasuk();

        $this->assertNotNull($invoice);
        $barang->refresh();
        $marking->refresh();
        $this->assertNotNull($marking->user_id);
        $this->assertSame((int) $marking->user_id, (int) $barang->user_id);
        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_sync_barang_customer_user_ids_fills_null_rows(): void
    {
        $customer = User::factory()->create(['name' => 'Portal']);
        $customer->assignRole('customer');

        $marking = Marking::query()->create([
            'code' => 'EK/ON/GAXYB74',
            'user_id' => $customer->id,
            'customer_name' => 'Alwin',
            'is_active' => true,
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-GAXY-1',
            'marking' => $marking->code,
            'user_id' => null,
            'customer_name' => 'Alwin',
            'price_per_cbm' => 7_000_000,
            'total_cbm' => 1.0,
            'status' => Barang::STATUS_STUFFING,
        ]);

        $updated = $marking->syncBarangCustomerUserIds();

        $this->assertSame(1, $updated);
        $this->assertSame($customer->id, (int) $barang->fresh()->user_id);
    }
}
