<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marking_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marking_id')->constrained('markings')->cascadeOnDelete();
            $table->foreignId('pricing_item_id')->constrained('master_pricing_items')->cascadeOnDelete();
            $table->decimal('volume_price', 12, 2)->default(0);
            $table->decimal('gw_price', 12, 2)->default(0);
            $table->decimal('minimum_charge', 15, 2)->default(0);
            $table->date('effective_start')->nullable();
            $table->unsignedSmallInteger('invoice_due_days')->default(0);
            $table->decimal('special_volume', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marking_pricings');
    }
};
