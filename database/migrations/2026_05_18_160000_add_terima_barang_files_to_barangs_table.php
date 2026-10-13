<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('barangs')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            if (! Schema::hasColumn('barangs', 'tracking_number')) {
                $table->string('tracking_number', 120)->nullable()->after('no_resi');
            }
            if (! Schema::hasColumn('barangs', 'tracking_receipt_path')) {
                $table->string('tracking_receipt_path', 255)->nullable()->after('tracking_number');
            }
            if (! Schema::hasColumn('barangs', 'good_receipt_path')) {
                $table->string('good_receipt_path', 255)->nullable()->after('tracking_receipt_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangs')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            foreach (['good_receipt_path', 'tracking_receipt_path', 'tracking_number'] as $column) {
                if (Schema::hasColumn('barangs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
