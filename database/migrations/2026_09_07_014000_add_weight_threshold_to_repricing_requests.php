<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('repricing_requests')) {
            return;
        }

        Schema::table('repricing_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('repricing_requests', 'old_weight_threshold_kg')) {
                $table->decimal('old_weight_threshold_kg', 15, 2)->nullable()->after('old_minimum_charge_cbm');
            }
            if (! Schema::hasColumn('repricing_requests', 'new_weight_threshold_kg')) {
                $table->decimal('new_weight_threshold_kg', 15, 2)->nullable()->after('new_minimum_charge_cbm');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('repricing_requests')) {
            return;
        }

        Schema::table('repricing_requests', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('repricing_requests', 'old_weight_threshold_kg')) {
                $cols[] = 'old_weight_threshold_kg';
            }
            if (Schema::hasColumn('repricing_requests', 'new_weight_threshold_kg')) {
                $cols[] = 'new_weight_threshold_kg';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
