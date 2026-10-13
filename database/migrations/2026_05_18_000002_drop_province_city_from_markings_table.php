<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            if (Schema::hasColumn('markings', 'city_id')) {
                $table->dropForeign(['city_id']);
                $table->dropColumn('city_id');
            }
            if (Schema::hasColumn('markings', 'province_id')) {
                $table->dropForeign(['province_id']);
                $table->dropColumn('province_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            if (! Schema::hasColumn('markings', 'province_id')) {
                $table->foreignId('province_id')->nullable()->after('promo')->constrained('provinces')->nullOnDelete();
            }
            if (! Schema::hasColumn('markings', 'city_id')) {
                $table->foreignId('city_id')->nullable()->after('province_id')->constrained('cities')->nullOnDelete();
            }
        });
    }
};
