<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('ctns')->default(0);
            $table->string('product_name', 200)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('material', 200)->nullable();
            $table->unsignedInteger('pcs_per_box')->default(0);
            $table->decimal('gross_weight_kg', 10, 2)->default(0);
            $table->decimal('length_cm', 10, 2)->default(0);
            $table->decimal('width_cm', 10, 2)->default(0);
            $table->decimal('height_cm', 10, 2)->default(0);
            $table->decimal('volume_cbm', 12, 6)->default(0);
            $table->decimal('price_per_unit_rmb', 14, 3)->default(0);
            $table->decimal('total_price_rmb', 14, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang_products');
    }
};
