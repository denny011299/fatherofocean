<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->date('effective_start')->nullable()->after('passcode');
            $table->unsignedSmallInteger('invoice_due_days')->default(0)->after('effective_start');
            $table->decimal('max_weight_kg', 12, 2)->default(0)->after('invoice_due_days');
        });

        if (! Schema::hasTable('marking_pricings')) {
            return;
        }

        DB::table('markings')->orderBy('id')->chunkById(100, function ($markings): void {
            foreach ($markings as $marking) {
                $pricing = DB::table('marking_pricings')
                    ->where('marking_id', $marking->id)
                    ->orderByDesc('effective_start')
                    ->orderByDesc('id')
                    ->first();

                if ($pricing === null) {
                    continue;
                }

                DB::table('markings')->where('id', $marking->id)->update([
                    'effective_start' => $pricing->effective_start,
                    'invoice_due_days' => $pricing->invoice_due_days ?? 0,
                    'max_weight_kg' => $pricing->weight_threshold_kg ?? 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->dropColumn(['effective_start', 'invoice_due_days', 'max_weight_kg']);
        });
    }
};
