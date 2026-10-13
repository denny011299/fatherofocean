<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_revenue_item_modals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_item_id')->constrained('master_pricing_items')->cascadeOnDelete();
            $table->decimal('cost_volume_price', 12, 2)->default(0);
            $table->decimal('cost_gw_price', 12, 2)->default(0);
            $table->timestamps();

            $table->unique('pricing_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_revenue_item_modals');
    }
};
