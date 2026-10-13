<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->string('seal_no', 30)->nullable()->after('container_no');
            $table->string('branch', 50)->nullable()->after('type');
            $table->date('load_date')->nullable()->after('max_volume');
            $table->date('etd')->nullable()->after('load_date');
            $table->date('eta')->nullable()->after('etd');
            $table->decimal('insurance_value', 15, 2)->default(0)->after('eta');
            $table->decimal('insurance_rate', 5, 2)->default(0)->after('insurance_value');
            $table->date('insurance_date')->nullable()->after('insurance_rate');
            $table->date('gate_pass_date')->nullable()->after('insurance_date');
            $table->date('arrival_date_actual')->nullable()->after('gate_pass_date');
            $table->boolean('is_join')->default(false)->after('arrival_date_actual');
            $table->string('route', 100)->nullable()->after('is_join');
            $table->string('department_item', 100)->nullable()->after('route');
            $table->string('length_type', 20)->nullable()->after('department_item');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropColumn([
                'seal_no', 'branch', 'load_date', 'etd', 'eta',
                'insurance_value', 'insurance_rate', 'insurance_date',
                'gate_pass_date', 'arrival_date_actual', 'is_join',
                'route', 'department_item', 'length_type',
            ]);
        });
    }
};
