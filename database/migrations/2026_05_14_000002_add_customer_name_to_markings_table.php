<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->string('customer_name', 150)->nullable()->after('user_id');
        });

        if (Schema::hasColumn('users', 'name')) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement(
                    'UPDATE markings SET customer_name = (SELECT users.name FROM users WHERE users.id = markings.user_id) WHERE customer_name IS NULL AND user_id IS NOT NULL'
                );
            } else {
                DB::table('markings')
                    ->join('users', 'markings.user_id', '=', 'users.id')
                    ->whereNull('markings.customer_name')
                    ->update(['markings.customer_name' => DB::raw('users.name')]);
            }
        }

        Schema::table('markings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('markings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('markings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('markings', function (Blueprint $table) {
            $table->dropColumn('customer_name');
        });
    }
};
