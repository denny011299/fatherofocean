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
        if (! Schema::hasTable('sales_incentive_tier_pricing_item')) {
            Schema::create('sales_incentive_tier_pricing_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_incentive_tier_id')
                    ->constrained('sales_incentive_tiers')
                    ->cascadeOnDelete();
                $table->foreignId('pricing_item_id')
                    ->constrained('master_pricing_items')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['sales_incentive_tier_id', 'pricing_item_id'],
                    'sit_pricing_item_unique'
                );
                $table->index('pricing_item_id', 'sit_pricing_item_pricing_idx');
            });
        }

        if (! Schema::hasTable('sales_incentive_tiers') || ! Schema::hasColumn('sales_incentive_tiers', 'pricing_item_id')) {
            return;
        }

        $now = now();
        $rows = DB::table('sales_incentive_tiers')
            ->whereNotNull('pricing_item_id')
            ->where('pricing_item_id', '>', 0)
            ->get(['id', 'pricing_item_id']);

        foreach ($rows as $row) {
            $exists = DB::table('sales_incentive_tier_pricing_item')
                ->where('sales_incentive_tier_id', $row->id)
                ->where('pricing_item_id', $row->pricing_item_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('sales_incentive_tier_pricing_item')->insert([
                'sales_incentive_tier_id' => $row->id,
                'pricing_item_id' => $row->pricing_item_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_incentive_tier_pricing_item');
    }
};
