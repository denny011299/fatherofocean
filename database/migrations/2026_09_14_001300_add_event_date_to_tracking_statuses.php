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
        if (Schema::hasTable('container_tracking_statuses')
            && ! Schema::hasColumn('container_tracking_statuses', 'event_date')) {
            Schema::table('container_tracking_statuses', function (Blueprint $table) {
                $table->date('event_date')->nullable()->after('description');
                $table->index('event_date');
            });

            DB::table('container_tracking_statuses')
                ->whereNull('event_date')
                ->update(['event_date' => DB::raw('DATE(created_at)')]);
        }

        if (Schema::hasTable('tracking_statuses')
            && ! Schema::hasColumn('tracking_statuses', 'event_date')) {
            Schema::table('tracking_statuses', function (Blueprint $table) {
                $table->date('event_date')->nullable()->after('description');
                $table->index('event_date');
            });

            DB::table('tracking_statuses')
                ->whereNull('event_date')
                ->update(['event_date' => DB::raw('DATE(created_at)')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('container_tracking_statuses')
            && Schema::hasColumn('container_tracking_statuses', 'event_date')) {
            Schema::table('container_tracking_statuses', function (Blueprint $table) {
                $table->dropIndex(['event_date']);
                $table->dropColumn('event_date');
            });
        }

        if (Schema::hasTable('tracking_statuses')
            && Schema::hasColumn('tracking_statuses', 'event_date')) {
            Schema::table('tracking_statuses', function (Blueprint $table) {
                $table->dropIndex(['event_date']);
                $table->dropColumn('event_date');
            });
        }
    }
};
