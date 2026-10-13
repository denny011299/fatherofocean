<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barang_products', function (Blueprint $table): void {
            $table->decimal('price_per_unit_rmb', 14, 3)->default(0)->change();
            $table->decimal('total_price_rmb', 14, 3)->default(0)->change();
        });

        Schema::table('barangs', function (Blueprint $table): void {
            $table->decimal('price', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('barang_products', function (Blueprint $table): void {
            $table->decimal('price_per_unit_rmb', 14, 2)->default(0)->change();
            $table->decimal('total_price_rmb', 14, 2)->default(0)->change();
        });

        Schema::table('barangs', function (Blueprint $table): void {
            $table->decimal('price', 15, 2)->default(0)->change();
        });
    }
};
