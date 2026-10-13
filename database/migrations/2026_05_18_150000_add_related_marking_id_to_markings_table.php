<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('markings') || Schema::hasColumn('markings', 'related_marking_id')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->foreignId('related_marking_id')
                ->nullable()
                ->after('user_id')
                ->constrained('markings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('markings') || ! Schema::hasColumn('markings', 'related_marking_id')) {
            return;
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->dropForeign(['related_marking_id']);
            $table->dropColumn('related_marking_id');
        });
    }
};
