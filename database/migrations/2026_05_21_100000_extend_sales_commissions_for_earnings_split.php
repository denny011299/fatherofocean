<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_commissions', function (Blueprint $table) {
            $table->decimal('company_profit_amount', 15, 2)->default(0)->after('billable_base');
            $table->decimal('sales_commission_base', 15, 2)->default(0)->after('company_profit_amount');
            $table->decimal('cost_subtotal', 15, 2)->default(0)->after('sales_commission_base');
            $table->decimal('markup_amount', 15, 2)->default(0)->after('cost_subtotal');
            $table->foreignId('supervisor_id')->nullable()->after('sales_id')->constrained('users')->nullOnDelete();
            $table->decimal('supervisor_amount', 15, 2)->default(0)->after('markup_amount');
            $table->timestamp('settled_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn([
                'company_profit_amount',
                'sales_commission_base',
                'cost_subtotal',
                'markup_amount',
                'supervisor_amount',
                'settled_at',
            ]);
        });
    }
};
