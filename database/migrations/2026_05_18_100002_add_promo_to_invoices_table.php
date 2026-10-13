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
            $table->foreignId('promo_id')->nullable()->after('extra_charges')->constrained('master_promos')->nullOnDelete();
            $table->string('promo_name', 100)->nullable()->after('promo_id');
            $table->decimal('promo_persen', 5, 2)->nullable()->after('promo_name');
            $table->decimal('promo_discount', 15, 2)->default(0)->after('promo_persen');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['promo_id']);
            $table->dropColumn(['promo_id', 'promo_name', 'promo_persen', 'promo_discount']);
        });
    }
};
