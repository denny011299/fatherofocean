<?php

declare(strict_types=1);

use App\Models\Barang;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('barangs', 'status')) {
            DB::statement(
                "ALTER TABLE barangs MODIFY status VARCHAR(50) NOT NULL DEFAULT '" . Barang::STATUS_STUFFING . "'",
            );
        }

        if (Schema::hasTable('tracking_statuses') && Schema::hasColumn('tracking_statuses', 'status')) {
            DB::statement('ALTER TABLE tracking_statuses MODIFY status VARCHAR(50) NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('barangs', 'status')) {
            DB::statement(
                "ALTER TABLE barangs MODIFY status VARCHAR(30) NOT NULL DEFAULT '" . Barang::STATUS_STUFFING . "'",
            );
        }

        if (Schema::hasTable('tracking_statuses') && Schema::hasColumn('tracking_statuses', 'status')) {
            DB::statement('ALTER TABLE tracking_statuses MODIFY status VARCHAR(50) NOT NULL');
        }
    }
};
