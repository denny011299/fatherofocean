<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_incentive_tiers', function (Blueprint $table) {
            $table->decimal('cbm_min', 12, 4)->nullable()->after('name');
            $table->decimal('cbm_max', 12, 4)->nullable()->after('cbm_min');
        });
    }

    public function down(): void
    {
        Schema::table('sales_incentive_tiers', function (Blueprint $table) {
            $table->dropColumn(['cbm_min', 'cbm_max']);
        });
    }
};
