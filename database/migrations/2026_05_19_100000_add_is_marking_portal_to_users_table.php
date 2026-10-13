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
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'is_marking_portal')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_marking_portal')->default(false)->after('marking_code');
            });
        }

        if (! Schema::hasTable('markings')) {
            return;
        }

        $userIds = DB::table('markings')
            ->whereNotNull('user_id')
            ->whereNotNull('passcode')
            ->where('passcode', '!=', '')
            ->pluck('user_id')
            ->unique()
            ->filter();

        if ($userIds->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $userIds)
                ->update(['is_marking_portal' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_marking_portal')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_marking_portal');
        });
    }
};
