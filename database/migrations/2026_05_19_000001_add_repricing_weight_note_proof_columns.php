<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repricing_requests', function (Blueprint $table) {
            $table->string('pricing_mode', 10)->default('cbm')->after('status');
            $table->decimal('old_weight_price', 12, 2)->nullable()->after('old_extra_charge');
            $table->decimal('new_weight_price', 12, 2)->nullable()->after('new_extra_charge');
            $table->text('reason')->nullable()->after('rejection_note');
            $table->string('proof_path')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('repricing_requests', function (Blueprint $table) {
            $table->dropColumn([
                'pricing_mode',
                'old_weight_price',
                'new_weight_price',
                'reason',
                'proof_path',
            ]);
        });
    }
};
