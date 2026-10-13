<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('barangs') && ! Schema::hasColumn('barangs', 'minimum_charge_cbm')) {
            Schema::table('barangs', function (Blueprint $table) {
                $table->decimal('minimum_charge_cbm', 15, 2)->nullable()->after('weight_threshold_kg');
            });
        }

        if (Schema::hasTable('repricing_requests')) {
            Schema::table('repricing_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('repricing_requests', 'old_minimum_charge_cbm')) {
                    $table->decimal('old_minimum_charge_cbm', 15, 2)->nullable()->after('old_weight_price');
                }
                if (! Schema::hasColumn('repricing_requests', 'new_minimum_charge_cbm')) {
                    $table->decimal('new_minimum_charge_cbm', 15, 2)->nullable()->after('new_weight_price');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('barangs') && Schema::hasColumn('barangs', 'minimum_charge_cbm')) {
            Schema::table('barangs', function (Blueprint $table) {
                $table->dropColumn('minimum_charge_cbm');
            });
        }

        if (Schema::hasTable('repricing_requests')) {
            Schema::table('repricing_requests', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('repricing_requests', 'old_minimum_charge_cbm')) {
                    $cols[] = 'old_minimum_charge_cbm';
                }
                if (Schema::hasColumn('repricing_requests', 'new_minimum_charge_cbm')) {
                    $cols[] = 'new_minimum_charge_cbm';
                }
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
