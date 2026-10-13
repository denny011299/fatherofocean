<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_trips')) {
            Schema::table('delivery_trips', function (Blueprint $table): void {
                if (Schema::hasColumn('delivery_trips', 'truck_plate')) {
                    $table->string('truck_plate', 30)->nullable()->change();
                }
                if (Schema::hasColumn('delivery_trips', 'driver_name')) {
                    $table->string('driver_name', 120)->nullable()->change();
                }
            });
        }

        if (Schema::hasTable('delivery_trip_marking_summaries')) {
            Schema::table('delivery_trip_marking_summaries', function (Blueprint $table): void {
                $table->string('truck_plate', 30)->nullable()->after('marking_key');
                $table->string('driver_name', 120)->nullable()->after('truck_plate');
                $table->string('driver_phone', 30)->nullable()->after('driver_name');
                $table->string('destination', 255)->nullable()->after('driver_phone');
                $table->text('trip_notes')->nullable()->after('destination');
                $table->string('status', 20)->default('ready')->after('trip_notes');
                $table->timestamp('departed_at')->nullable()->after('status');
                $table->foreignId('departed_confirmed_by')->nullable()->after('departed_at')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('arrived_at')->nullable()->after('departed_confirmed_by');
                $table->foreignId('arrived_confirmed_by')->nullable()->after('arrived_at')
                    ->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('delivery_trip_media') && ! Schema::hasColumn('delivery_trip_media', 'marking_key')) {
            Schema::table('delivery_trip_media', function (Blueprint $table): void {
                $table->string('marking_key', 255)->nullable()->after('delivery_trip_id');
                $table->index(['delivery_trip_id', 'marking_key', 'phase']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('delivery_trip_media') && Schema::hasColumn('delivery_trip_media', 'marking_key')) {
            Schema::table('delivery_trip_media', function (Blueprint $table): void {
                $table->dropIndex(['delivery_trip_id', 'marking_key', 'phase']);
                $table->dropColumn('marking_key');
            });
        }

        if (Schema::hasTable('delivery_trip_marking_summaries')) {
            Schema::table('delivery_trip_marking_summaries', function (Blueprint $table): void {
                $table->dropForeign(['departed_confirmed_by']);
                $table->dropForeign(['arrived_confirmed_by']);
                $table->dropColumn([
                    'truck_plate',
                    'driver_name',
                    'driver_phone',
                    'destination',
                    'trip_notes',
                    'status',
                    'departed_at',
                    'departed_confirmed_by',
                    'arrived_at',
                    'arrived_confirmed_by',
                ]);
            });
        }
    }
};
