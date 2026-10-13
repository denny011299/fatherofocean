<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repricing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->decimal('old_price_per_cbm', 12, 2)->default(0);
            $table->decimal('old_extra_charge', 12, 2)->default(0);
            $table->decimal('new_price_per_cbm', 12, 2);
            $table->decimal('new_extra_charge', 12, 2)->default(0);
            $table->text('rejection_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['barang_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repricing_requests');
    }
};
