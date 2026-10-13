<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total_cbm', 12, 4)->default(0);
            $table->decimal('billable_base', 15, 2)->default(0);
            $table->decimal('commission_rate', 5, 2)->default(2.5);
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->decimal('confirmed_amount', 15, 2)->nullable();
            $table->string('status', 20)->default('waiting');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['sales_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commissions');
    }
};
