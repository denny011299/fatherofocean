<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Container;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AlignContainerBarangStatusesCommand extends Command
{
    protected $signature = 'tracking:align-container-barangs
                            {--partai= : Limit to container partai}
                            {--container= : Limit to container id}
                            {--dry-run : Preview mismatches without updating}';

    protected $description = 'Upgrade lagging barang statuses to match advanced container tracking (ETD/ETA/bea cukai/bongkar)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $partai = is_string($this->option('partai')) ? trim((string) $this->option('partai')) : '';
        $containerId = $this->option('container') !== null && $this->option('container') !== ''
            ? (int) $this->option('container')
            : null;

        $query = Container::query()
            ->with(['latestTracking', 'barangs'])
            ->whereHas('barangs')
            ->whereHas('trackingStatuses', static function ($q): void {
                $q->whereIn('status', [
                    Container::TRACKING_ETD,
                    Container::TRACKING_ETA,
                    Container::TRACKING_BEA_CUKAI,
                    Container::TRACKING_BONGKAR,
                ]);
            });

        if ($partai !== '') {
            $query->where('partai', $partai);
        }

        if ($containerId !== null) {
            $query->whereKey($containerId);
        }

        $containers = $query->orderBy('id')->get();
        $mismatchRows = 0;
        $fixed = 0;

        foreach ($containers as $container) {
            $tracking = $container->trackingStatusBeyondLoading();
            if ($tracking === null) {
                continue;
            }

            $expected = Container::barangStatusForTrackingStatus($tracking);
            $expectedRank = Container::barangShipmentProgressRank($expected);

            $lagging = $container->barangs->filter(static function ($barang) use ($expected, $expectedRank): bool {
                if (\App\Models\Barang::isDitarikKembaliStatus($barang->status)) {
                    return false;
                }

                $rank = Container::barangShipmentProgressRank((string) $barang->status);

                return $rank < $expectedRank || (
                    $rank === $expectedRank && (string) $barang->status !== $expected
                );
            });

            if ($lagging->isEmpty()) {
                continue;
            }

            $mismatchRows += $lagging->count();
            $this->line(sprintf(
                'Partai %s (#%d) tracking=%s expected=%s lagging=%d [%s]',
                $container->partai ?: '-',
                $container->id,
                $tracking,
                $expected,
                $lagging->count(),
                $lagging->pluck('no_index')->implode(', '),
            ));

            if ($dryRun) {
                continue;
            }

            $fixed += DB::transaction(static function () use ($container): int {
                return $container->alignLaggingBarangsToCurrentTracking(null);
            });
        }

        if ($mismatchRows === 0) {
            $this->info('No lagging barang statuses found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment("Dry-run: {$mismatchRows} barang(s) would be upgraded.");

            return self::SUCCESS;
        }

        $this->info("Upgraded {$fixed} barang(s) across {$containers->count()} container(s).");

        return self::SUCCESS;
    }
}
