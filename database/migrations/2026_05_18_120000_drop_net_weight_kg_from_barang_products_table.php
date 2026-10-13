<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('barang_products', 'net_weight_kg')) {
            return;
        }

        Schema::table('barang_products', function (Blueprint $table): void {
            $table->dropColumn('net_weight_kg');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('barang_products', 'net_weight_kg')) {
            return;
        }

        Schema::table('barang_products', function (Blueprint $table): void {
            $table->decimal('net_weight_kg', 10, 2)->default(0)->after('gross_weight_kg');
        });
    }
};
