<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\DailyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class DailyRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_finance_user_can_update_daily_rate(): void
    {
        $user = User::factory()->create();
        $user->assignRole('finance');

        $response = $this->actingAs($user)->putJson(route('api.daily-rate.update'), [
            'rate' => 2350,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.rate', 2350);

        $this->assertSame(2350.0, DailyRate::rate());
        $this->assertNotNull(DailyRate::effectiveDate());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Daily rate updated',
            'user_id' => $user->id,
        ]);
    }

    public function test_sales_user_cannot_update_daily_rate(): void
    {
        $user = User::factory()->create();
        $user->assignRole('sales');

        $this->actingAs($user)
            ->putJson(route('api.daily-rate.update'), ['rate' => 2000])
            ->assertForbidden();
    }

    public function test_supervisor_user_cannot_update_daily_rate(): void
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        $this->actingAs($user)
            ->putJson(route('api.daily-rate.update'), ['rate' => 2000])
            ->assertForbidden();
    }

    public function test_authenticated_user_can_fetch_daily_rate(): void
    {
        DailyRate::update(2400.0);

        $user = User::factory()->create();
        $user->assignRole('sales');

        $this->actingAs($user)
            ->getJson(route('api.daily-rate'))
            ->assertOk()
            ->assertJsonPath('rate', 2400);
    }

    public function test_apply_to_request_overrides_submitted_rate_on_create(): void
    {
        DailyRate::update(2250.0);

        $request = Request::create('/', 'POST', ['rate' => '9999']);
        DailyRate::applyToRequest($request);

        $this->assertSame('2250', $request->input('rate'));
    }

    public function test_apply_to_request_preserves_submitted_rate_on_update(): void
    {
        DailyRate::update(2690.0);

        $request = Request::create('/', 'PUT', ['rate' => '2500']);
        DailyRate::applyToRequest($request, preserveExisting: true);

        $this->assertSame('2500', $request->input('rate'));
    }
}
