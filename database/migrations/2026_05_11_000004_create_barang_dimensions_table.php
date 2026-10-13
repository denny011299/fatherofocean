<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang_dimensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained()->cascadeOnDelete();
            $table->decimal('panjang', 8, 2)->default(0);
            $table->decimal('lebar', 8, 2)->default(0);
            $table->decimal('tinggi', 8, 2)->default(0);
            $table->integer('ctn_count')->default(1);
            $table->decimal('volume_cbm', 10, 6)->default(0);
            $table->decimal('gross_weight', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang_dimensions');
    }
};
