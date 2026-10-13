<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('promo_type', 20)->nullable()->after('promo_name');
            $table->decimal('promo_amount_rp', 15, 2)->nullable()->after('promo_persen');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['promo_type', 'promo_amount_rp']);
        });
    }
};
