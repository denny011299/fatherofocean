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
            $table->string('total_invoice', 100)->nullable()->after('price_per_cbm');
            $table->string('invoice_file')->nullable()->after('total_invoice');
            $table->decimal('weight_price', 12, 2)->default(0)->after('invoice_file');
            $table->decimal('cbm_threshold', 12, 2)->default(0)->after('weight_price');
        });
    }

    public function down(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn(['total_invoice', 'invoice_file', 'weight_price', 'cbm_threshold']);
        });
    }
};
