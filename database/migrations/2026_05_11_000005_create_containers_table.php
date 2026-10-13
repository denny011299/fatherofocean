<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('container_no', 30)->nullable();
            $table->string('type', 20)->default('40ft');
            $table->decimal('max_length', 8, 2)->default(0);
            $table->decimal('max_width', 8, 2)->default(0);
            $table->decimal('max_height', 8, 2)->default(0);
            $table->decimal('max_volume', 10, 2)->default(0);
            $table->foreignId('vessel_id')->nullable()->constrained()->nullOnDelete();
            $table->date('departure_date')->nullable();
            $table->date('arrival_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
