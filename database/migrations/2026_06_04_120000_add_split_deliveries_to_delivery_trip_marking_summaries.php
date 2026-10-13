<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_trip_marking_summaries')) {
            return;
        }

        Schema::table('delivery_trip_marking_summaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_trip_marking_summaries', 'split_deliveries')) {
                // Surat Jalan PDF multi-page config only (not invoice/finance split).
                $table->json('split_deliveries')->nullable()->after('received_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_trip_marking_summaries')) {
            return;
        }

        Schema::table('delivery_trip_marking_summaries', function (Blueprint $table): void {
            if (Schema::hasColumn('delivery_trip_marking_summaries', 'split_deliveries')) {
                $table->dropColumn('split_deliveries');
            }
        });
    }
};
