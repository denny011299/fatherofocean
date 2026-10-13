<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barang_dimensions', function (Blueprint $table) {
            $table->dropColumn([
                'ctn_no',
                'qty_ctn',
                'qty',
                'circ',
                'round_val',
                'va',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('barang_dimensions', function (Blueprint $table) {
            $table->string('ctn_no', 30)->nullable()->after('item_name');
            $table->integer('qty_ctn')->default(0)->after('ctn_no');
            $table->integer('qty')->default(0)->after('ctn_count');
            $table->decimal('circ', 8, 2)->default(0)->after('tinggi');
            $table->decimal('round_val', 8, 2)->default(0)->after('circ');
            $table->decimal('va', 8, 2)->default(0)->after('round_val');
        });
    }
};
