<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Marking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MarkingCustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    public function test_find_for_customer_login_with_valid_marking_code_and_passcode(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $marking = Marking::query()->create([
            'code' => 'BS/5758/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Test Customer',
            'passcode' => 'secret123',
            'is_active' => true,
        ]);

        $found = Marking::findForCustomerLogin('BS/5758/SEA', 'secret123');

        $this->assertNotNull($found);
        $this->assertSame($marking->id, $found->id);
    }

    public function test_find_for_customer_login_fails_with_wrong_passcode(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        Marking::query()->create([
            'code' => 'BS/5758/SEA',
            'user_id' => $customer->id,
            'customer_name' => 'Test Customer',
            'passcode' => 'secret123',
            'is_active' => true,
        ]);

        $this->assertNull(Marking::findForCustomerLogin('BS/5758/SEA', 'wrong'));
    }

    public function test_find_for_customer_login_provisions_customer_user_when_missing(): void
    {
        $marking = Marking::query()->create([
            'code' => 'BS/5758/SEA',
            'user_id' => null,
            'customer_name' => 'Joe Kiem Sing',
            'passcode' => '12345678',
            'is_active' => true,
        ]);

        $found = Marking::findForCustomerLogin('BS/5758/SEA', '12345678');

        $this->assertNotNull($found);
        $this->assertNotNull($found->user_id);
        $this->assertTrue($found->customer?->hasRole('customer'));
    }
}
