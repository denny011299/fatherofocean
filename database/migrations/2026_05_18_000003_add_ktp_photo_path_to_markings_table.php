<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            if (! Schema::hasColumn('markings', 'ktp_photo_path')) {
                $table->string('ktp_photo_path')->nullable()->after('nik_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            if (Schema::hasColumn('markings', 'ktp_photo_path')) {
                $table->dropColumn('ktp_photo_path');
            }
        });
    }
};
