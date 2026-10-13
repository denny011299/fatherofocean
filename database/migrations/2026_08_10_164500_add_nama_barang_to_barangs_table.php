<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('barangs')) {
            return;
        }

        if (Schema::hasColumn('barangs', 'nama_barang')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            $table->string('nama_barang', 255)->nullable()->after('cbm_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangs') || ! Schema::hasColumn('barangs', 'nama_barang')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn('nama_barang');
        });
    }
};
