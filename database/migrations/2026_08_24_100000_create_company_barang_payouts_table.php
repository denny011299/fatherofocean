<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_barang_payouts')) {
            return;
        }

        Schema::create('company_barang_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('barang_id')->unique()->constrained('barangs')->cascadeOnDelete();
            $table->string('proof_file');
            $table->date('payout_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_barang_payouts');
    }
};
