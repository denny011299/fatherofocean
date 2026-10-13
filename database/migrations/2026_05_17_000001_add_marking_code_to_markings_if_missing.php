<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings') || Schema::hasColumn('markings', 'code')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('user_id');
        });

        Schema::table('markings', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('markings') || ! Schema::hasColumn('markings', 'code')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
