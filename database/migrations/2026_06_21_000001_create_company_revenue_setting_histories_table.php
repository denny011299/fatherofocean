<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_revenue_setting_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 120);
            $table->decimal('old_value', 15, 2)->nullable();
            $table->decimal('new_value', 15, 2);
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['setting_key', 'changed_at'], 'crsh_setting_key_changed_at_idx');
            $table->index('changed_by_user_id', 'crsh_changed_by_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_revenue_setting_histories');
    }
};
