<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SalesCommissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesCommissionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    }

    public function test_default_rate_from_settings(): void
    {
        SalesCommissionSettings::update(3.25);

        $this->assertSame(3.25, SalesCommissionSettings::defaultRate());
    }

    public function test_supervisor_user_uses_default_sales_rate_when_no_override(): void
    {
        SalesCommissionSettings::update(2.5);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $this->assertSame(2.5, SalesCommissionSettings::rateForSalesUser($supervisor));
        $this->assertSame(2.5, SalesCommissionSettings::rateForSalesUser($sales));
    }

    public function test_fallback_when_settings_missing(): void
    {
        SystemSetting::query()->where('key', SalesCommissionSettings::KEY_DEFAULT)->delete();

        $this->assertSame(2.5, SalesCommissionSettings::defaultRate());
    }

    public function test_sales_override_takes_precedence_over_default(): void
    {
        SalesCommissionSettings::update(2.5);

        $sales = User::factory()->create([
            'sales_commission_rate_override' => 5.0,
        ]);
        $sales->assignRole('sales');

        $this->assertSame(5.0, SalesCommissionSettings::rateForSalesUser($sales));
        $this->assertSame(5.0, SalesCommissionSettings::rateForSalesId($sales->id));
    }

    public function test_supervisor_override_column_does_not_affect_rate(): void
    {
        SalesCommissionSettings::update(2.5);

        $supervisor = User::factory()->create([
            'sales_commission_rate_override' => 7.5,
        ]);
        $supervisor->assignRole('supervisor');

        $this->assertSame(2.5, SalesCommissionSettings::rateForSalesUser($supervisor));
    }
}
