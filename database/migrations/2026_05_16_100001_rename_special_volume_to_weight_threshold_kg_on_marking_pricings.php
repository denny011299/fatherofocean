<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marking_pricings', function (Blueprint $table) {
            $table->renameColumn('special_volume', 'weight_threshold_kg');
        });
    }

    public function down(): void
    {
        Schema::table('marking_pricings', function (Blueprint $table) {
            $table->renameColumn('weight_threshold_kg', 'special_volume');
        });
    }
};
