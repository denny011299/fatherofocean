<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('barangs', 'weight')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('barangs', 'weight')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table) {
            $table->decimal('weight', 10, 2)->default(0)->after('rate');
        });
    }
};
