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
            $table->foreignId('parent_id')
                ->nullable()
                ->after('barang_id')
                ->constrained('barang_products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('barang_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
