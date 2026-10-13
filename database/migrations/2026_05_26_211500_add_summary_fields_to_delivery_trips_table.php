<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table): void {
            $table->string('print_marking_code', 255)->nullable()->after('arrived_confirmed_by');
            $table->string('print_customer_name', 255)->nullable()->after('print_marking_code');
            $table->string('print_customer_phone', 60)->nullable()->after('print_customer_name');
            $table->text('print_customer_address')->nullable()->after('print_customer_phone');
            $table->date('print_loading_date')->nullable()->after('print_customer_address');
            $table->text('print_item_name')->nullable()->after('print_loading_date');
            $table->string('print_total_coly', 60)->nullable()->after('print_item_name');
            $table->string('print_partai', 255)->nullable()->after('print_total_coly');
            $table->text('print_notes')->nullable()->after('print_partai');
            $table->string('receiver_name', 255)->nullable()->after('print_notes');
            $table->string('receiver_phone', 60)->nullable()->after('receiver_name');
            $table->date('received_date')->nullable()->after('receiver_phone');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table): void {
            $table->dropColumn([
                'print_marking_code',
                'print_customer_name',
                'print_customer_phone',
                'print_customer_address',
                'print_loading_date',
                'print_item_name',
                'print_total_coly',
                'print_partai',
                'print_notes',
                'receiver_name',
                'receiver_phone',
                'received_date',
            ]);
        });
    }
};
