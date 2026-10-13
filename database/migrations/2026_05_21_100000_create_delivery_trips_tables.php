<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_no', 40)->unique();
            $table->foreignId('container_id')->nullable()->constrained()->nullOnDelete();
            $table->string('truck_plate', 30);
            $table->string('driver_name', 120);
            $table->string('driver_phone', 30)->nullable();
            $table->string('destination', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('departed_at')->nullable();
            $table->foreignId('departed_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('arrived_at')->nullable();
            $table->foreignId('arrived_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('container_id');
        });

        Schema::create('delivery_trip_barang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['delivery_trip_id', 'barang_id']);
        });

        Schema::create('delivery_trip_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20);
            $table->string('media_type', 10);
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delivery_trip_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_trip_media');
        Schema::dropIfExists('delivery_trip_barang');
        Schema::dropIfExists('delivery_trips');
    }
};
