<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Form label alignment (Container Baru):
 * - batch  -> partai   (column: code -> partai)
 * - seal   -> no_bl    (column: seal_no -> no_bl)
 * - vessel -> port     (column: vessel_id -> port_id, FK ports)
 * - arrival date -> notes (drop arrival_date, add notes)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('containers')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // 1) seal_no -> no_bl
        if (Schema::hasColumn('containers', 'seal_no') && ! Schema::hasColumn('containers', 'no_bl')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `containers` CHANGE `seal_no` `no_bl` VARCHAR(30) NULL');
            } else {
                Schema::table('containers', function (Blueprint $table): void {
                    $table->renameColumn('seal_no', 'no_bl');
                });
            }
        }

        // 2) vessel_id -> port_id (data vessel_id is not auto-mapped; re-select port in UI after migrate)
        if (Schema::hasColumn('containers', 'vessel_id')) {
            $this->dropForeignKeyIfExists('containers', 'containers_vessel_id_foreign');

            if (! Schema::hasColumn('containers', 'port_id')) {
                Schema::table('containers', function (Blueprint $table): void {
                    $table->foreignId('port_id')
                        ->nullable()
                        ->after('department_item')
                        ->constrained('ports')
                        ->nullOnDelete();
                });
            }

            Schema::table('containers', function (Blueprint $table): void {
                $table->dropColumn('vessel_id');
            });
        }

        // 3) arrival_date -> notes
        if (! Schema::hasColumn('containers', 'notes')) {
            Schema::table('containers', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('gate_pass_date');
            });
        }

        if (Schema::hasColumn('containers', 'arrival_date')) {
            Schema::table('containers', function (Blueprint $table): void {
                $table->dropColumn('arrival_date');
            });
        }

        // 4) code (UI: BATCH) -> partai
        if (Schema::hasColumn('containers', 'code') && ! Schema::hasColumn('containers', 'partai')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `containers` CHANGE `code` `partai` VARCHAR(30) NOT NULL');
            } else {
                Schema::table('containers', function (Blueprint $table): void {
                    $table->renameColumn('code', 'partai');
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('containers')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasColumn('containers', 'partai') && ! Schema::hasColumn('containers', 'code')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `containers` CHANGE `partai` `code` VARCHAR(30) NOT NULL');
            } else {
                Schema::table('containers', function (Blueprint $table): void {
                    $table->renameColumn('partai', 'code');
                });
            }
        }

        if (! Schema::hasColumn('containers', 'arrival_date')) {
            Schema::table('containers', function (Blueprint $table): void {
                $table->date('arrival_date')->nullable()->after('departure_date');
            });
        }

        if (Schema::hasColumn('containers', 'notes')) {
            Schema::table('containers', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }

        if (! Schema::hasColumn('containers', 'vessel_id')) {
            Schema::table('containers', function (Blueprint $table): void {
                $table->foreignId('vessel_id')
                    ->nullable()
                    ->after('max_volume')
                    ->constrained('vessels')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('containers', 'port_id')) {
            $this->dropForeignKeyIfExists('containers', 'containers_port_id_foreign');

            Schema::table('containers', function (Blueprint $table): void {
                $table->dropColumn('port_id');
            });
        }

        if (Schema::hasColumn('containers', 'no_bl') && ! Schema::hasColumn('containers', 'seal_no')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `containers` CHANGE `no_bl` `seal_no` VARCHAR(30) NULL');
            } else {
                Schema::table('containers', function (Blueprint $table): void {
                    $table->renameColumn('no_bl', 'seal_no');
                });
            }
        }
    }

    private function dropForeignKeyIfExists(string $table, string $foreignKey): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                    $blueprint->dropForeign($foreignKey);
                });
            } catch (\Throwable) {
                // Ignore missing FK on sqlite/tests.
            }

            return;
        }

        $database = $connection->getDatabaseName();

        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignKey)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreignKey}`");
        }
    }
};
