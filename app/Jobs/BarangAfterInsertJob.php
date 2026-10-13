<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Barang;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Runs after HTTP response (no queue worker required). */
final class BarangAfterInsertJob
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $barangId,
        public ?int $actorId,
    ) {}

    public function handle(): void
    {
        $barang = Barang::query()->find($this->barangId);
        if ($barang) {
            $barang->afterInsert($this->actorId);
        }
    }
}
