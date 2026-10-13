<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marking_pricings')) {
            return;
        }

        if (! Schema::hasColumn('marking_pricings', 'item_name')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $table->text('item_name')->nullable()->after('pricing_item_id');
            });

            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE marking_pricings MODIFY item_name TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('marking_pricings') || ! Schema::hasColumn('marking_pricings', 'item_name')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE marking_pricings MODIFY item_name VARCHAR(255) NULL');
        }
    }
};
