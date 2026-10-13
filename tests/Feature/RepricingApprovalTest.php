<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RepricingRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class RepricingApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function makeBarang(array $overrides = []): Barang
    {
        return Barang::query()->create(array_merge([
            'no_index' => 'IDX-' . uniqid(),
            'marking' => 'MARK-01',
            'customer_name' => 'PT Contoh',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
            'price_per_cbm' => 0,
            'extra_charge' => 0,
        ], $overrides));
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submitRepricing(User $user, Barang $barang, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Storage::fake('public');

        return $this->actingAs($user)->post(route('finance.reprice', $barang), array_merge([
            'pricing_mode' => 'cbm',
            'price_per_cbm' => 1500,
            'extra_charge' => 0,
            'reason' => 'Koreksi harga',
            'proof_image' => UploadedFile::fake()->image('proof.jpg'),
        ], $overrides));
    }

    public function test_submit_creates_pending_request_without_updating_barang(): void
    {
        $barang = $this->makeBarang(['price_per_cbm' => 1000, 'extra_charge' => 50]);
        $submitter = $this->userWithPermissions(['submit repricing']);

        $response = $this->submitRepricing($submitter, $barang, [
            'price_per_cbm' => 1500,
            'extra_charge' => 100,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $barang->refresh();
        $this->assertSame('1000.00', $barang->price_per_cbm);
        $this->assertSame('50.00', $barang->extra_charge);

        $request = RepricingRequest::query()->where('barang_id', $barang->id)->first();
        $this->assertNotNull($request);
        $this->assertSame(RepricingRequest::STATUS_PENDING, $request->status);
        $this->assertSame('1500.00', $request->new_price_per_cbm);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Repricing submitted',
            'model_type' => Barang::class,
            'model_id' => $barang->id,
            'user_id' => $submitter->id,
        ]);
    }

    public function test_user_without_submit_permission_cannot_submit(): void
    {
        $barang = $this->makeBarang();
        $user = $this->userWithPermissions(['approve repricing']);

        $this->submitRepricing($user, $barang, ['price_per_cbm' => 1200])
            ->assertForbidden();
    }

    public function test_approver_can_apply_price_and_logs_activity(): void
    {
        $barang = $this->makeBarang(['price_per_cbm' => 1000, 'extra_charge' => 0]);
        $submitter = $this->userWithPermissions(['submit repricing']);
        $approver = $this->userWithPermissions(['approve repricing']);

        $this->submitRepricing($submitter, $barang, [
            'price_per_cbm' => 2000,
            'extra_charge' => 25,
        ])->assertOk();

        $request = RepricingRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->postJson(route('finance.repricing.approve', $request))
            ->assertOk()
            ->assertJson(['success' => true]);

        $barang->refresh();
        $request->refresh();

        $this->assertSame('2000.00', $barang->price_per_cbm);
        $this->assertSame('25.00', $barang->extra_charge);
        $this->assertSame(RepricingRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($approver->id, $request->reviewed_by);

        $log = ActivityLog::query()->where('action', 'Repricing approved')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('2000', $log->detailSummary());
    }

    public function test_approve_extra_charge_syncs_open_invoice_item_and_totals(): void
    {
        $customer = User::factory()->create();
        $barang = $this->makeBarang([
            'user_id' => $customer->id,
            'total_cbm' => 0.444,
            'total_gross_weight' => 84,
            'price_per_cbm' => 5_500_000,
            'weight_price' => 10_000,
            'weight_threshold_kg' => 500,
            'extra_charge' => 0,
            'status' => Barang::STATUS_BONGKAR,
        ]);
        $submitter = $this->userWithPermissions(['submit repricing']);
        $approver = $this->userWithPermissions(['approve repricing']);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INV-REPRICE-'.uniqid(),
            'user_id' => $customer->id,
            'price_per_cbm' => 5_500_000,
            'status' => 'sent',
            'due_date' => now()->toDateString(),
            'created_by' => $approver->id,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'barang_id' => $barang->id,
            'cbm' => 0.444,
            'price_per_cbm' => 5_500_000,
            'insurance' => 0,
            'extra' => 0,
        ]);
        $invoice->recalculate();
        $this->assertSame(0.0, (float) $invoice->fresh()->extra_charges);

        $this->submitRepricing($submitter, $barang, [
            'price_per_cbm' => 5_500_000,
            'weight_price' => 10_000,
            'extra_charge' => 100_000,
        ])->assertOk();

        $request = RepricingRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->postJson(route('finance.repricing.approve', $request))
            ->assertOk()
            ->assertJson(['success' => true]);

        $barang->refresh();
        $invoice->refresh();
        $item = $invoice->items()->firstOrFail();

        $this->assertSame('100000.00', $barang->extra_charge);
        $this->assertSame('100000.00', $item->extra);
        $this->assertSame(100_000.0, (float) $invoice->extra_charges);
        $this->assertGreaterThanOrEqual(2_542_000.0, (float) $invoice->grand_total);
    }

    public function test_submitter_cannot_approve_own_request(): void
    {
        $barang = $this->makeBarang(['price_per_cbm' => 500]);
        $user = $this->userWithPermissions(['submit repricing', 'approve repricing']);

        $this->submitRepricing($user, $barang, ['price_per_cbm' => 800])->assertOk();

        $request = RepricingRequest::query()->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('finance.repricing.approve', $request))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_reject_does_not_update_barang_price(): void
    {
        $barang = $this->makeBarang(['price_per_cbm' => 900]);
        $submitter = $this->userWithPermissions(['submit repricing']);
        $approver = $this->userWithPermissions(['approve repricing']);

        $this->submitRepricing($submitter, $barang, ['price_per_cbm' => 1200]);

        $request = RepricingRequest::query()->firstOrFail();

        $this->actingAs($approver)->postJson(route('finance.repricing.reject', $request), [
            'rejection_note' => 'Harga tidak sesuai marking',
        ])->assertOk();

        $barang->refresh();
        $request->refresh();

        $this->assertSame('900.00', $barang->price_per_cbm);
        $this->assertSame(RepricingRequest::STATUS_REJECTED, $request->status);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Repricing rejected',
            'user_id' => $approver->id,
        ]);
    }

    public function test_weight_mode_submit_and_approve_updates_weight_price(): void
    {
        $barang = $this->makeBarang([
            'price_per_cbm' => 1000,
            'weight_price' => 500,
            'total_gross_weight' => 10,
        ]);
        $submitter = $this->userWithPermissions(['submit repricing']);
        $approver = $this->userWithPermissions(['approve repricing']);

        $this->submitRepricing($submitter, $barang, [
            'pricing_mode' => 'weight',
            'weight_price' => 750,
            'price_per_cbm' => 0,
        ])->assertOk();

        $request = RepricingRequest::query()->where('barang_id', $barang->id)->firstOrFail();
        $this->assertSame(RepricingRequest::MODE_WEIGHT, $request->pricing_mode);

        $this->actingAs($approver)
            ->postJson(route('finance.repricing.approve', $request))
            ->assertOk();

        $barang->refresh();
        $this->assertSame('1000.00', $barang->price_per_cbm);
        $this->assertSame('750.00', $barang->weight_price);
    }

    public function test_finance_role_has_repricing_permissions_after_seeder(): void
    {
        $user = User::factory()->create();
        $user->assignRole('finance');

        $this->assertTrue($user->can('submit repricing'));
        $this->assertTrue($user->can('approve repricing'));
    }

    public function test_supervisor_has_repricing_permissions_via_seeder(): void
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        $this->assertTrue($user->can('approve repricing'));
        $this->assertTrue($user->can('submit repricing'));
    }
}
