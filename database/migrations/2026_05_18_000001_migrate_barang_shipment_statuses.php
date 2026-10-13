<?php

declare(strict_types=1);

use App\Models\Barang;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'draft' => Barang::STATUS_STUFFING,
        'inbound' => Barang::STATUS_STUFFING,
        'loaded' => Barang::STATUS_STUFFING,
        'shipped' => Barang::STATUS_ETD,
        'in_transit' => Barang::STATUS_ETD,
        'out_for_delivery' => Barang::STATUS_ETA,
        'arrived' => Barang::STATUS_ETA,
        'delivered' => Barang::STATUS_BONGKAR,
    ];

    public function up(): void
    {
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('barangs')->where('status', $old)->update(['status' => $new]);
        }

        if (Schema::hasTable('tracking_statuses')) {
            foreach (self::STATUS_MAP as $old => $new) {
                DB::table('tracking_statuses')->where('status', $old)->update(['status' => $new]);
            }
        }

        if (Schema::hasColumn('barangs', 'status') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE barangs MODIFY status VARCHAR(30) NOT NULL DEFAULT '" . Barang::STATUS_STUFFING . "'");
        }
    }

    public function down(): void
    {
        $reverse = [
            Barang::STATUS_STUFFING => 'inbound',
            Barang::STATUS_ETD => 'in_transit',
            Barang::STATUS_ETA => 'arrived',
            Barang::STATUS_BONGKAR => 'delivered',
        ];

        foreach ($reverse as $new => $old) {
            DB::table('barangs')->where('status', $new)->update(['status' => $old]);
        }

        if (Schema::hasTable('tracking_statuses')) {
            foreach ($reverse as $new => $old) {
                DB::table('tracking_statuses')->where('status', $new)->update(['status' => $old]);
            }
        }

        if (Schema::hasColumn('barangs', 'status') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE barangs MODIFY status VARCHAR(30) NOT NULL DEFAULT 'draft'");
        }
    }
};
