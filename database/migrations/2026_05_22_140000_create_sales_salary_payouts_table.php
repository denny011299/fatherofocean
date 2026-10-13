<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_salary_payouts')) {
            return;
        }

        Schema::create('sales_salary_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->char('period', 7);
            $table->string('proof_file')->nullable();
            $table->foreignId('proof_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->unique(['sales_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_salary_payouts');
    }
};
