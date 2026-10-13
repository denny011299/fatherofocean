<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings') || ! Schema::hasColumn('markings', 'promo')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->dropColumn('promo');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('markings')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            if (! Schema::hasColumn('markings', 'promo')) {
                $table->string('promo')->nullable()->after('address_2');
            }
        });
    }
};
