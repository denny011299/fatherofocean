<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_trip_marking_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
            $table->string('marking_key', 255);
            $table->string('marking_code', 255)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_phone', 60)->nullable();
            $table->string('customer_address', 255)->nullable();
            $table->date('loading_date')->nullable();
            $table->string('item_name', 255)->nullable();
            $table->string('total_coly', 60)->nullable();
            $table->string('partai', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('receiver_name', 255)->nullable();
            $table->string('receiver_phone', 60)->nullable();
            $table->date('received_date')->nullable();
            $table->timestamps();

            $table->unique(['delivery_trip_id', 'marking_key'], 'delivery_trip_marking_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_trip_marking_summaries');
    }
};
