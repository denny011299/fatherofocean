<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('repricing_requests')) {
            return;
        }

        Schema::table('repricing_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('repricing_requests', 'pricing_mode')) {
                $table->string('pricing_mode', 10)->default('cbm')->after('status');
            }
            if (! Schema::hasColumn('repricing_requests', 'old_weight_price')) {
                $table->decimal('old_weight_price', 12, 2)->nullable()->after('old_extra_charge');
            }
            if (! Schema::hasColumn('repricing_requests', 'new_weight_price')) {
                $table->decimal('new_weight_price', 12, 2)->nullable()->after('new_extra_charge');
            }
            if (! Schema::hasColumn('repricing_requests', 'reason')) {
                $table->text('reason')->nullable()->after('rejection_note');
            }
            if (! Schema::hasColumn('repricing_requests', 'proof_path')) {
                $table->string('proof_path')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('repricing_requests')) {
            return;
        }

        Schema::table('repricing_requests', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('repricing_requests', 'proof_path') ? 'proof_path' : null,
                Schema::hasColumn('repricing_requests', 'reason') ? 'reason' : null,
                Schema::hasColumn('repricing_requests', 'new_weight_price') ? 'new_weight_price' : null,
                Schema::hasColumn('repricing_requests', 'old_weight_price') ? 'old_weight_price' : null,
                Schema::hasColumn('repricing_requests', 'pricing_mode') ? 'pricing_mode' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
