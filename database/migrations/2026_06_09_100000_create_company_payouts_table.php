<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_payouts')) {
            return;
        }

        Schema::create('company_payouts', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->date('payout_date');
            $table->string('proof_file');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('payout_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payouts');
    }
};
