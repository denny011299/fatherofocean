<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            $table->decimal('cost_price_per_cbm', 12, 2)->nullable()->after('price_per_cbm');
            $table->decimal('cost_weight_price', 12, 2)->nullable()->after('weight_price');
            $table->decimal('company_loyalty_amount', 15, 2)->nullable()->after('extra_charge');
        });
    }

    public function down(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn([
                'cost_price_per_cbm',
                'cost_weight_price',
                'company_loyalty_amount',
            ]);
        });
    }
};
