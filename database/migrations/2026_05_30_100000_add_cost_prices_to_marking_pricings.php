<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marking_pricings', function (Blueprint $table) {
            $table->decimal('cost_volume_price', 12, 2)->nullable()->after('gw_price');
            $table->decimal('cost_gw_price', 12, 2)->nullable()->after('cost_volume_price');
        });
    }

    public function down(): void
    {
        Schema::table('marking_pricings', function (Blueprint $table) {
            $table->dropColumn(['cost_volume_price', 'cost_gw_price']);
        });
    }
};
