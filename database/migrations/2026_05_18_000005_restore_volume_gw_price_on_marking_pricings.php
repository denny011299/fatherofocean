<?php

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

        if (! Schema::hasColumn('marking_pricings', 'volume_price')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $table->decimal('volume_price', 12, 2)->default(0)->after('pricing_item_id');
            });
        }

        if (! Schema::hasColumn('marking_pricings', 'gw_price')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $after = Schema::hasColumn('marking_pricings', 'volume_price') ? 'volume_price' : 'pricing_item_id';
                $table->decimal('gw_price', 12, 2)->default(0)->after($after);
            });
        }

        if (! Schema::hasTable('master_pricing_items')) {
            return;
        }

        if (! Schema::hasColumn('master_pricing_items', 'volume_price')) {
            return;
        }

        DB::table('marking_pricings')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                if ((float) ($row->volume_price ?? 0) > 0 || (float) ($row->gw_price ?? 0) > 0) {
                    continue;
                }

                $master = DB::table('master_pricing_items')
                    ->where('id', $row->pricing_item_id)
                    ->first(['volume_price', 'gw_price']);

                if ($master === null) {
                    continue;
                }

                DB::table('marking_pricings')->where('id', $row->id)->update([
                    'volume_price' => $master->volume_price ?? 0,
                    'gw_price' => $master->gw_price ?? 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marking_pricings')) {
            return;
        }

        if (Schema::hasColumn('marking_pricings', 'volume_price')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $table->dropColumn('volume_price');
            });
        }

        if (Schema::hasColumn('marking_pricings', 'gw_price')) {
            Schema::table('marking_pricings', function (Blueprint $table) {
                $table->dropColumn('gw_price');
            });
        }
    }
};
