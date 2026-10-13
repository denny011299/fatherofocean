<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_marking_commission_overrides')) {
            return;
        }

        Schema::create('sales_marking_commission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
            $table->string('marking', 100);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('total_komisi', 15, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_id', 'marking']);
            $table->index(['sales_id', 'marking']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_marking_commission_overrides');
    }
};
