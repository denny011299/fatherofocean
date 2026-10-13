<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_subordinates')) {
            return;
        }

        Schema::create('user_subordinates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subordinate_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['parent_user_id', 'subordinate_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subordinates');
    }
};
