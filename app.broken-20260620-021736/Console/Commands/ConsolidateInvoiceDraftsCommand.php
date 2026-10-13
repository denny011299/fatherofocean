<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\Invoice;
use App\Support\InvoiceConsolidation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConsolidateInvoiceDraftsCommand extends Command
{
    protected $signature = 'invoices:consolidate-drafts
                            {--user= : Limit to customer user_id}
                            {--marking= : Limit to barangs.marking value (resolved via marking master)}
                            {--dry-run : List draft groups without merging}';

    protected $description = 'Merge split draft invoices per customer + marking (one invoice, many no_index)';

    public function handle(): int
    {
        $userFilter = $this->option('user') !== null ? (int) $this->option('user') : null;
        $markingFilter = is_string($this->option('marking')) && $this->option('marking') !== ''
            ? trim((string) $this->option('marking'))
            : null;
        $dryRun = (bool) $this->option('dry-run');

        $pairs = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('barangs', 'invoice_items.barang_id', '=', 'barangs.id')
            ->where('invoices.status', 'draft')
            ->when($userFilter !== null, static fn ($q) => $q->where('invoices.user_id', $userFilter))
            ->when($markingFilter !== null, static fn ($q) => $q->where('barangs.marking', $markingFilter))
            ->select('invoices.user_id', 'barangs.marking')
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            $this->info('No draft invoice scopes found.');

            return self::SUCCESS;
        }

        $mergedGroups = 0;

        foreach ($pairs as $pair) {
            $userId = (int) $pair->user_id;
            $marking = trim((string) $pair->marking);
            if ($marking === '') {
                continue;
            }

            $anchor = Barang::query()
                ->where('user_id', $userId)
                ->where('marking', $marking)
                ->first();

            if ($anchor === null) {
                continue;
            }

            $markingKey = InvoiceConsolidation::markingKey($anchor);
            $draftCount = Invoice::query()
                ->where('user_id', $userId)
                ->where('status', 'draft')
                ->whereHas('items.barang', static fn ($q) => $q->where('marking', $marking))
                ->count();

            if ($draftCount <= 1) {
                continue;
            }

            $this->line(sprintf(
                'user_id=%d marking=%s (%s): %d draft invoice(s)',
                $userId,
                $marking,
                $markingKey,
                $draftCount,
            ));

            if ($dryRun) {
                $mergedGroups++;

                continue;
            }

            InvoiceConsolidation::consolidateOpenDraftsForBarangScope($anchor);
            $mergedGroups++;
        }

        if ($dryRun) {
            $this->info("Dry run: {$mergedGroups} scope(s) would be consolidated.");
        } else {
            $this->info("Consolidated {$mergedGroups} scope(s).");
        }

        return self::SUCCESS;
    }
}
