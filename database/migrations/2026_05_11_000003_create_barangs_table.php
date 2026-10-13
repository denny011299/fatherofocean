<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangs', function (Blueprint $table) {
            $table->id();
            $table->string('no_index', 50)->unique();
            $table->string('marking', 100)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('warehouse_origin', 50)->default('guangzhou');
            $table->date('tanggal_masuk')->nullable();
            $table->integer('ctns')->default(0);
            $table->integer('pcs')->default(0);
            $table->string('material', 100)->nullable();
            $table->decimal('insurance_value', 15, 2)->default(0);
            $table->decimal('insurance_rate', 5, 2)->default(0);
            $table->decimal('total_cbm', 10, 4)->default(0);
            $table->decimal('total_gross_weight', 10, 2)->default(0);
            $table->string('destination', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('price_per_cbm', 12, 2)->default(0);
            $table->decimal('extra_charge', 12, 2)->default(0);
            $table->boolean('is_preload')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('marking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangs');
    }
};
