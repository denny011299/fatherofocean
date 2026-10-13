<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_incentive_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('cbm_min', 12, 4)->nullable();
            $table->decimal('cbm_max', 12, 4)->nullable();
            $table->decimal('cbm_per_block', 12, 4);
            $table->decimal('amount_per_block', 15, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sales_incentive_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barangs')->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('total_cbm', 12, 4)->default(0);
            $table->decimal('paid_cbm', 12, 4)->default(0);
            $table->timestamp('stuffed_at')->nullable();
            $table->timestamp('invoice_paid_at')->nullable();
            $table->timestamps();

            $table->unique('barang_id');
            $table->index(['sales_id', 'period']);
        });

        Schema::create('sales_incentive_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('cbm_stuffing', 12, 4)->default(0);
            $table->decimal('cbm_paid', 12, 4)->default(0);
            $table->decimal('estimasi_amount', 15, 2)->default(0);
            $table->decimal('cair_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['sales_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_incentive_periods');
        Schema::dropIfExists('sales_incentive_lines');
        Schema::dropIfExists('sales_incentive_tiers');
    }
};
