<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Barang;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Runs after HTTP response (no queue worker required). */
final class BarangAfterUpdateJob
{
    use Dispatchable;
    use SerializesModels;

    /** @param array<string, mixed> $old */
    /** @param array<string, mixed> $new */
    public function __construct(
        public int $barangId,
        public array $old,
        public array $new,
    ) {}

    public function handle(): void
    {
        $barang = Barang::query()->find($this->barangId);
        if ($barang) {
            $barang->afterUpdate($this->old, $this->new);
        }
    }
}
