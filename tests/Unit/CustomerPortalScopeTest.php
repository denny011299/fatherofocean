<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\User;
use App\Support\CustomerPortalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomerPortalScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    public function test_barang_scope_filters_by_session_marking_code(): void
    {
        $customer = User::factory()->create(['is_marking_portal' => true]);
        $customer->assignRole('customer');

        $marking = Marking::query()->create([
            'code' => 'BS/5758/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Joe',
            'passcode' => '12345678',
            'is_active' => true,
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-001',
            'marking' => 'BS/5758/SEA',
            'customer_name' => 'Joe',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-002',
            'marking' => 'EK/ON/MNAFE',
            'customer_name' => 'Other',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($customer);
        session(['customer_marking_id' => $marking->id]);

        $query = Barang::query();
        CustomerPortalScope::applyBarangScope($query);

        $this->assertSame(1, $query->count());
        $this->assertSame('IDX-001', $query->value('no_index'));
    }

    public function test_barang_scope_includes_all_related_marking_codes(): void
    {
        $customer = User::factory()->create(['is_marking_portal' => true]);
        $customer->assignRole('customer');

        $hub = Marking::query()->create([
            'code' => 'HUB/001/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Hub Customer',
            'passcode' => '12345678',
            'is_active' => true,
        ]);

        $related = Marking::query()->create([
            'code' => 'REL/002/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Hub Customer',
            'related_marking_id' => $hub->id,
            'passcode' => '87654321',
            'is_active' => true,
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-HUB',
            'marking' => 'HUB/001/SEA',
            'customer_name' => 'Hub Customer',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-REL',
            'marking' => 'REL/002/SEA',
            'customer_name' => 'Hub Customer',
            'status' => Barang::STATUS_ETD,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER/999',
            'customer_name' => 'Someone else',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($customer);
        session(['customer_marking_id' => $hub->id]);

        $query = Barang::query();
        CustomerPortalScope::applyBarangScope($query);

        $this->assertSame(2, $query->count());
        $this->assertEqualsCanonicalizing(
            ['IDX-HUB', 'IDX-REL'],
            $query->pluck('no_index')->all(),
        );

        session(['customer_marking_id' => $related->id]);
        $queryRelatedLogin = Barang::query();
        CustomerPortalScope::applyBarangScope($queryRelatedLogin);

        $this->assertSame(2, $queryRelatedLogin->count());
    }

    public function test_subordinate_login_sees_related_marking_cluster(): void
    {
        $customer = User::factory()->create(['is_marking_portal' => true]);
        $customer->assignRole('customer');

        $parentUser = User::factory()->create(['is_marking_portal' => true]);
        $parentUser->assignRole('customer');

        $hub = Marking::query()->create([
            'code' => 'HUB/ONLY/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Hub',
            'passcode' => '12345678',
            'is_active' => true,
        ]);

        $childMarking = Marking::query()->create([
            'code' => 'CHILD/ONLY/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Hub',
            'related_marking_id' => $hub->id,
            'passcode' => '87654321',
            'is_active' => true,
        ]);

        $parentUser->subordinates()->attach($customer->id);

        Barang::query()->create([
            'no_index' => 'IDX-HUB-ONLY',
            'marking' => 'HUB/ONLY/SEA',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-CHILD-ONLY',
            'marking' => 'CHILD/ONLY/SEA',
            'status' => Barang::STATUS_ETD,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($customer);
        session(['customer_marking_id' => $childMarking->id]);

        $query = Barang::query();
        CustomerPortalScope::applyBarangScope($query);

        $this->assertSame(2, $query->count());
        $this->assertEqualsCanonicalizing(
            ['IDX-HUB-ONLY', 'IDX-CHILD-ONLY'],
            $query->pluck('no_index')->all(),
        );
    }

    public function test_sara_sondakh_cluster_codes_and_invoices(): void
    {
        $customer = User::factory()->create(['is_marking_portal' => true]);
        $customer->assignRole('customer');

        $sara = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'user_id' => $customer->id,
            'customer_name' => 'YENNY',
            'passcode' => '11111111',
            'is_active' => true,
        ]);

        $sondakh = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'user_id' => $customer->id,
            'customer_name' => 'YENNY',
            'related_marking_id' => $sara->id,
            'passcode' => '22222222',
            'is_active' => true,
        ]);

        $saraBarang = Barang::query()->create([
            'no_index' => 'IDX-SARA',
            'marking' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        $sondakhBarang = Barang::query()->create([
            'no_index' => 'IDX-SONDAKH',
            'marking' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'status' => Barang::STATUS_ETD,
            'warehouse_origin' => 'guangzhou',
        ]);

        $saraInvoice = Invoice::query()->create([
            'invoice_no' => 'INV-SARA-001',
            'user_id' => $customer->id,
            'status' => 'sent',
            'grand_total' => 100000,
        ]);
        $saraInvoice->items()->create([
            'barang_id' => $saraBarang->id,
            'cbm' => 1,
            'price_per_cbm' => 100000,
            'line_total' => 100000,
        ]);

        $sondakhInvoice = Invoice::query()->create([
            'invoice_no' => 'INV-SONDAKH-001',
            'user_id' => $customer->id,
            'status' => 'sent',
            'grand_total' => 200000,
        ]);
        $sondakhInvoice->items()->create([
            'barang_id' => $sondakhBarang->id,
            'cbm' => 1,
            'price_per_cbm' => 200000,
            'line_total' => 200000,
        ]);

        $this->actingAs($customer);
        session(['customer_marking_id' => $sondakh->id]);

        $this->assertEqualsCanonicalizing(
            ['EK/ON/SARA', 'EK/ON/SONDAKH'],
            CustomerPortalScope::markingCodes($customer),
        );

        $barangQuery = Barang::query();
        CustomerPortalScope::applyBarangScope($barangQuery, $customer);
        $this->assertSame(2, $barangQuery->count());

        $invoiceQuery = Invoice::query();
        CustomerPortalScope::applyInvoiceScope($invoiceQuery, $customer);
        $this->assertSame(2, $invoiceQuery->count());

        session(['customer_marking_id' => $sara->id]);
        $this->assertEqualsCanonicalizing(
            ['EK/ON/SARA', 'EK/ON/SONDAKH'],
            CustomerPortalScope::markingCodes($customer),
        );
    }

    public function test_barang_scope_includes_assigned_subordinate_users(): void
    {
        $parent = User::factory()->create(['is_marking_portal' => true]);
        $parent->assignRole('customer');

        $child = User::factory()->create(['is_marking_portal' => true]);
        $child->assignRole('customer');

        $parent->subordinates()->attach($child->id);

        Marking::query()->create([
            'code' => 'CHILD/001',
            'user_id' => $child->id,
            'customer_name' => 'Child',
            'passcode' => '11111111',
            'is_active' => true,
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-CHILD',
            'marking' => 'CHILD/001',
            'user_id' => $child->id,
            'customer_name' => 'Child',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-OTHER2',
            'marking' => 'OTHER/XYZ',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($parent);

        $query = Barang::query();
        CustomerPortalScope::applyBarangScope($query);

        $this->assertSame(1, $query->count());
        $this->assertSame('IDX-CHILD', $query->value('no_index'));
    }

    public function test_customer_login_with_child_marking_resolves_cluster(): void
    {
        $customer = User::factory()->create(['is_marking_portal' => true]);
        $customer->assignRole('customer');

        $sara = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'user_id' => $customer->id,
            'customer_name' => 'YENNY',
            'passcode' => '11111111',
            'is_active' => true,
        ]);

        $sondakh = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'user_id' => $customer->id,
            'customer_name' => 'YENNY',
            'related_marking_id' => $sara->id,
            'passcode' => '22222222',
            'is_active' => true,
        ]);

        $this->actingAs($customer);
        session(['customer_marking_id' => $sondakh->id]);

        $this->assertEqualsCanonicalizing(
            ['EK/ON/SARA', 'EK/ON/SONDAKH'],
            CustomerPortalScope::markingCodes($customer),
        );
        $this->assertCount(2, CustomerPortalScope::clusterMarkings($customer));
    }

    public function test_subordinate_user_does_not_see_parent_merged_barang(): void
    {
        $parent = User::factory()->create(['is_marking_portal' => true]);
        $parent->assignRole('customer');

        $child = User::factory()->create(['is_marking_portal' => true]);
        $child->assignRole('customer');

        $parent->subordinates()->attach($child->id);

        Marking::query()->create([
            'code' => 'PARENT/MRK',
            'user_id' => $parent->id,
            'passcode' => '11111111',
            'is_active' => true,
        ]);
        Marking::query()->create([
            'code' => 'CHILD/MRK',
            'user_id' => $child->id,
            'passcode' => '22222222',
            'is_active' => true,
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-PARENT',
            'marking' => 'PARENT/MRK',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-CHILD-USER',
            'marking' => 'CHILD/MRK',
            'user_id' => $child->id,
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($child);
        session(['customer_marking_id' => Marking::query()->where('code', 'CHILD/MRK')->value('id')]);

        $query = Barang::query();
        CustomerPortalScope::applyBarangScope($query);

        $this->assertSame(1, $query->count());
        $this->assertSame('IDX-CHILD-USER', $query->value('no_index'));
    }
}
