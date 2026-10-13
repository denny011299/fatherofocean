<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_revenue_item_modals', function (Blueprint $table): void {
            $table->decimal('company_profit_per_shipment', 12, 2)->default(0)->after('cost_gw_price');
        });
    }

    public function down(): void
    {
        Schema::table('company_revenue_item_modals', function (Blueprint $table): void {
            $table->dropColumn('company_profit_per_shipment');
        });
    }
};
