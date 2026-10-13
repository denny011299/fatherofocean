<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            $table->string('no_po', 50)->nullable()->after('no_index');
            $table->string('supplier_chinese', 100)->nullable()->after('supplier_id');
            $table->decimal('price', 15, 3)->default(0)->after('pcs');
            $table->decimal('insurance_nominal_standar', 15, 2)->default(0)->after('insurance_rate');
            $table->decimal('insurance_nominal_custom', 15, 2)->default(0)->after('insurance_nominal_standar');
            $table->string('cbm_type', 20)->default('cbm')->after('total_gross_weight');
            $table->decimal('rate', 12, 2)->default(0)->after('cbm_type');
            $table->decimal('weight', 10, 2)->default(0)->after('rate');
            $table->string('form', 100)->nullable()->after('material');
            $table->text('material_desc')->nullable()->after('form');
            $table->string('other', 100)->nullable()->after('destination');
            $table->text('notes_id')->nullable()->after('notes');
        });

        Schema::table('barang_dimensions', function (Blueprint $table) {
            $table->string('item_name', 100)->nullable()->after('barang_id');
            $table->string('ctn_no', 30)->nullable()->after('item_name');
            $table->integer('qty_ctn')->default(0)->after('ctn_no');
            $table->integer('qty')->default(0)->after('ctn_count');
            $table->string('count_by', 20)->default('Box')->after('qty');
            $table->decimal('circ', 8, 2)->default(0)->after('tinggi');
            $table->decimal('round_val', 8, 2)->default(0)->after('circ');
            $table->decimal('va', 8, 2)->default(0)->after('round_val');
            $table->decimal('gw_per_ctn', 10, 2)->default(0)->after('va');
        });
    }

    public function down(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            $table->dropColumn([
                'no_po', 'supplier_chinese', 'price',
                'insurance_nominal_standar', 'insurance_nominal_custom',
                'cbm_type', 'rate', 'weight', 'form', 'material_desc',
                'other', 'notes_id',
            ]);
        });

        Schema::table('barang_dimensions', function (Blueprint $table) {
            $table->dropColumn([
                'item_name', 'ctn_no', 'qty_ctn', 'qty',
                'count_by', 'circ', 'round_val', 'va', 'gw_per_ctn',
            ]);
        });
    }
};
