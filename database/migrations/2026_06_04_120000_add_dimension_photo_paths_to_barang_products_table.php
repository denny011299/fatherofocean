<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barang_products', function (Blueprint $table) {
            $table->string('length_photo_path')->nullable()->after('length_cm');
            $table->string('width_photo_path')->nullable()->after('width_cm');
            $table->string('height_photo_path')->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('barang_products', function (Blueprint $table) {
            $table->dropColumn(['length_photo_path', 'width_photo_path', 'height_photo_path']);
        });
    }
};
