<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Barang;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Runs after HTTP response (no queue worker required). */
final class BarangAfterDeleteJob
{
    use Dispatchable;
    use SerializesModels;

    /** @param list<int> $invoiceIds */
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public array $invoiceIds,
        public array $snapshot,
    ) {}

    public function handle(): void
    {
        Barang::afterDelete($this->invoiceIds, $this->snapshot);
    }
}
