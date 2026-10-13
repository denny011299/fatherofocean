<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_promos', function (Blueprint $table) {
            $table->string('type', 20)->default('percent')->after('name');
            $table->decimal('amount_rp', 15, 2)->nullable()->after('persen');
        });

        Schema::table('master_promos', function (Blueprint $table) {
            $table->decimal('persen', 5, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('master_promos', function (Blueprint $table) {
            $table->dropColumn(['type', 'amount_rp']);
        });

        Schema::table('master_promos', function (Blueprint $table) {
            $table->decimal('persen', 5, 2)->default(0)->nullable(false)->change();
        });
    }
};
