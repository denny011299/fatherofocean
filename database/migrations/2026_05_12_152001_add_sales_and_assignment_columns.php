<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->foreignId('sales_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('review')
                ->constrained('users')->nullOnDelete();
            $table->text('admin_notes')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('admin_notes');
        });

        Schema::table('markings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_id');
        });
    }
};
