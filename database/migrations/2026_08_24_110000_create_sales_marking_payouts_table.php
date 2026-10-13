<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_marking_payouts')) {
            return;
        }

        Schema::create('sales_marking_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->string('marking', 100);
            $table->decimal('amount', 15, 2);
            $table->date('payout_date');
            $table->string('proof_file');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['sales_id', 'marking']);
            $table->index('payout_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_marking_payouts');
    }
};
