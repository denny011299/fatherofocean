<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('sales_commission_rate_override', 5, 2)
                ->nullable()
                ->after('phone')
                ->comment('Persentase komisi khusus user; null = pakai pengaturan umum per role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sales_commission_rate_override');
        });
    }
};
