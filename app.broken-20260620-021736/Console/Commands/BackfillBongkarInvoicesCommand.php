<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Barang;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class BackfillBongkarInvoicesCommand extends Command
{
    protected $signature = 'invoices:backfill-bongkar
                            {--user= : Limit to customer user_id}
                            {--marking= : Limit to barangs.marking value}
                            {--in-container : Only barangs linked on container_barangs (muat container)}
                            {--limit=500 : Max barangs processed per run}
                            {--dry-run : Preview counts without creating invoices}
                            {--sample=5 : With --dry-run, list up to N sample barang IDs}';

    protected $description = 'Create draft invoices for bongkar barangs (incl. container loads) using standard auto-invoice pricing';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $userFilter = $this->option('user') !== null ? (int) $this->option('user') : null;
        $markingFilter = is_string($this->option('marking')) && $this->option('marking') !== ''
            ? trim((string) $this->option('marking'))
            : null;
        $inContainerOnly = (bool) $this->option('in-container');
        $dryRun = (bool) $this->option('dry-run');
        $sampleSize = max(0, (int) $this->option('sample'));

        $query = $this->pendingQuery($inContainerOnly, $userFilter, $markingFilter);

        $candidates = (clone $query)->count();
        $billableNow = (clone $query)
            ->whereNotNull('user_id')
            ->where('price_per_cbm', '>', 0)
            ->count();

        $blockedBase = (clone $query);
        $blockedMissingUser = (clone $blockedBase)->whereNull('user_id')->count();
        $blockedZeroPrice = (clone $blockedBase)
            ->whereNotNull('user_id')
            ->where(static function ($q): void {
                $q->whereNull('price_per_cbm')->orWhere('price_per_cbm', '<=', 0);
            })
            ->count();

        $projectedBillable = 0;
        $projectedBlocked = 0;

        if ($dryRun && $candidates > 0 && $candidates <= 2000) {
            (clone $query)->orderBy('id')->chunkById(200, function ($barangs) use (&$projectedBillable, &$projectedBlocked): void {
                foreach ($barangs as $barang) {
                    if ($barang->projectedInvoiceSkipReasonOnBongkar() === null) {
                        $projectedBillable++;
                    } else {
                        $projectedBlocked++;
                    }
                }
            });
        }

        if ($candidates === 0) {
            $this->info('No bongkar barangs need invoice backfill'.$this->scopeLabel($inContainerOnly).'.');

            return self::SUCCESS;
        }

        $this->line('Scope: status=bongkar, no invoice_items'.$this->scopeLabel($inContainerOnly).'.');
        $this->line("Candidates: {$candidates} (billable in DB now: {$billableNow}).");

        if ($blockedMissingUser > 0 || $blockedZeroPrice > 0) {
            $this->comment("Blocked in DB: {$blockedMissingUser} missing user_id, {$blockedZeroPrice} price_per_cbm zero or null.");
        }

        if ($projectedBillable > 0 || $projectedBlocked > 0) {
            $this->comment("After marking pricing (read-only): {$projectedBillable} billable, {$projectedBlocked} still blocked.");
        } elseif ($dryRun && $candidates > 2000) {
            $this->comment('Projected billable count skipped (>2000 rows); run with --user or --marking to narrow.');
        }

        if ($dryRun) {
            $processable = $projectedBillable > 0 ? $projectedBillable : $candidates;
            $this->info("Dry run: up to {$limit} of ~{$processable} would be processed this run (uses Barang::recordInvoiceIfBongkar / BarangShippingPricing).");

            if ($sampleSize > 0) {
                $this->printSample($query, $sampleSize);
            }

            return self::SUCCESS;
        }

        $created = Barang::backfillMissingInvoicesForBongkar($query, $limit);

        $this->info("Created or consolidated invoices for {$created} bongkar barang(s).");

        if ($candidates > $created) {
            $this->comment('Re-run the command if more barangs remain (marking may resolve blocked rows on each pass).');
        }

        return self::SUCCESS;
    }

    private function pendingQuery(bool $inContainerOnly, ?int $userFilter, ?string $markingFilter): Builder
    {
        return Barang::query()
            ->pendingBongkarInvoiceBackfill($inContainerOnly)
            ->when($userFilter !== null, static fn ($q) => $q->where('user_id', $userFilter))
            ->when($markingFilter !== null, static fn ($q) => $q->where('marking', $markingFilter));
    }

    private function scopeLabel(bool $inContainerOnly): string
    {
        return $inContainerOnly ? ' (in container only)' : '';
    }

    private function printSample(Builder $query, int $sampleSize): void
    {
        $rows = (clone $query)
            ->orderByDesc('id')
            ->limit($sampleSize)
            ->get(['id', 'no_index', 'marking', 'user_id', 'price_per_cbm']);

        $this->newLine();
        $this->line('Sample barangs:');

        foreach ($rows as $barang) {
            $reason = $barang->projectedInvoiceSkipReasonOnBongkar();
            $status = $reason === null ? 'billable' : $reason;
            $this->line(sprintf(
                '  #%d %s marking=%s → %s',
                $barang->id,
                $barang->no_index ?? '-',
                $barang->marking ?? '-',
                $status,
            ));
        }
    }
}
