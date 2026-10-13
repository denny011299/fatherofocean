<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bongkar_muat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->text('notes')->nullable();
            $table->foreignId('started_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['container_id', 'status']);
        });

        Schema::create('bongkar_muat_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bongkar_muat_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained()->cascadeOnDelete();
            $table->string('no_index', 50);
            $table->unsignedInteger('expected_ctns');
            $table->unsignedInteger('confirmed_ctns');
            $table->string('variance_note', 500)->nullable();
            $table->foreignId('scanned_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['bongkar_muat_session_id', 'barang_id']);
            $table->index('no_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bongkar_muat_scans');
        Schema::dropIfExists('bongkar_muat_sessions');
    }
};
