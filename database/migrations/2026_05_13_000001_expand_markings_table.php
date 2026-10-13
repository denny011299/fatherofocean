<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->string('contact_person', 100)->nullable()->after('description');
            $table->string('email')->nullable()->after('contact_person');
            $table->string('pic', 100)->nullable()->after('email');
            $table->string('priority', 20)->default('non_vip')->after('pic');
            $table->foreignId('branch_id')->nullable()->after('priority')->constrained('master_branches')->nullOnDelete();
            $table->text('address_1')->nullable()->after('branch_id');
            $table->text('address_2')->nullable()->after('address_1');
            $table->string('promo')->nullable()->after('address_2');
            $table->foreignId('province_id')->nullable()->after('promo')->constrained('provinces')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('province_id')->constrained('cities')->nullOnDelete();
            $table->string('phone_hp', 30)->nullable()->after('city_id');
            $table->string('fax', 30)->nullable()->after('phone_hp');
            $table->string('phone_02', 30)->nullable()->after('fax');
            $table->string('wa_name', 100)->nullable()->after('phone_02');
            $table->string('nik', 30)->nullable()->after('wa_name');
            $table->string('nik_name', 100)->nullable()->after('nik');
            $table->string('passcode', 20)->nullable()->after('nik_name');

            $table->index('nik');
        });
    }

    public function down(): void
    {
        Schema::table('markings', function (Blueprint $table) {
            $table->dropIndex(['nik']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['province_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn([
                'contact_person', 'email', 'pic', 'priority', 'branch_id',
                'address_1', 'address_2', 'promo', 'province_id', 'city_id',
                'phone_hp', 'fax', 'phone_02', 'wa_name', 'nik', 'nik_name', 'passcode',
            ]);
        });
    }
};
