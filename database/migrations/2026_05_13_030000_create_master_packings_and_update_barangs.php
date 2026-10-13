<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_packings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = ['Karton', 'Pallet', 'Wooden Crate', 'Plastik', 'Bubble Wrap', 'Tanpa Packing'];
        foreach ($defaults as $name) {
            \DB::table('master_packings')->insert([
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('barangs', function (Blueprint $table) {
            if (!Schema::hasColumn('barangs', 'packing_id')) {
                $table->foreignId('packing_id')->nullable()->after('other')
                    ->constrained('master_packings')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('barangs', 'no_resi')) {
            Schema::table('barangs', function (Blueprint $table) {
                $table->text('no_resi')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('barangs', function (Blueprint $table) {
            if (Schema::hasColumn('barangs', 'packing_id')) {
                $table->dropConstrainedForeignId('packing_id');
            }
        });

        Schema::dropIfExists('master_packings');
    }
};
