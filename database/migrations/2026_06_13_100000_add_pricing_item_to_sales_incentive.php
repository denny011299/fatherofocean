<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_incentive_tiers', function (Blueprint $table) {
            $table->foreignId('pricing_item_id')
                ->nullable()
                ->after('name')
                ->constrained('master_pricing_items')
                ->nullOnDelete();
            $table->index(['pricing_item_id', 'sort_order']);
        });

        Schema::table('sales_incentive_lines', function (Blueprint $table) {
            $table->foreignId('pricing_item_id')
                ->nullable()
                ->after('barang_id')
                ->constrained('master_pricing_items')
                ->nullOnDelete();
            $table->index(['sales_id', 'period', 'pricing_item_id'], 'sales_incentive_lines_sales_period_category');
        });
    }

    public function down(): void
    {
        Schema::table('sales_incentive_lines', function (Blueprint $table) {
            $table->dropIndex('sales_incentive_lines_sales_period_category');
            $table->dropConstrainedForeignId('pricing_item_id');
        });

        Schema::table('sales_incentive_tiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_item_id');
        });
    }
};
