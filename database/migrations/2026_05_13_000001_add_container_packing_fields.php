<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('containers', 'alamat_gudang_china')) {
            Schema::table('containers', function (Blueprint $table) {
                $table->string('alamat_gudang_china', 255)->nullable()->after('branch');
            });
        }

        Schema::table('barangs', function (Blueprint $table) {
            if (!Schema::hasColumn('barangs', 'no_resi')) {
                $table->string('no_resi', 100)->nullable()->after('notes_id');
            }
            if (!Schema::hasColumn('barangs', 'keterangan')) {
                $table->text('keterangan')->nullable()->after('no_resi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropColumn(['alamat_gudang_china']);
        });

        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn(['no_resi', 'keterangan']);
        });
    }
};
