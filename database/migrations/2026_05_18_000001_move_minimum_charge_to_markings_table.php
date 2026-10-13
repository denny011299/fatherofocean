<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        if (! Schema::hasColumn('markings', 'minimum_charge')) {
            Schema::table('markings', function (Blueprint $table) {
                $table->decimal('minimum_charge', 15, 2)->default(0)->after('max_weight_kg');
            });
        }

        if (! Schema::hasTable('marking_pricings') || ! Schema::hasColumn('marking_pricings', 'minimum_charge')) {
            return;
        }

        DB::table('markings')->orderBy('id')->chunkById(100, function ($markings): void {
            foreach ($markings as $marking) {
                $maxMin = DB::table('marking_pricings')
                    ->where('marking_id', $marking->id)
                    ->max('minimum_charge');

                if ($maxMin === null) {
                    continue;
                }

                DB::table('markings')->where('id', $marking->id)->update([
                    'minimum_charge' => $maxMin,
                ]);
            }
        });

        Schema::table('marking_pricings', function (Blueprint $table) {
            $table->dropColumn('minimum_charge');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marking_pricings')) {
            return;
        }

        if (! Schema::hasColumn('marking_pricings', 'minimum_charge')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $table->decimal('minimum_charge', 15, 2)->default(0)->after('gw_price');
            });
        }

        if (Schema::hasColumn('markings', 'minimum_charge')) {
            DB::table('markings')->orderBy('id')->chunkById(100, function ($markings): void {
                foreach ($markings as $marking) {
                    DB::table('marking_pricings')
                        ->where('marking_id', $marking->id)
                        ->update(['minimum_charge' => $marking->minimum_charge ?? 0]);
                }
            });

            Schema::table('markings', function (Blueprint $table) {
                $table->dropColumn('minimum_charge');
            });
        }
    }
};
