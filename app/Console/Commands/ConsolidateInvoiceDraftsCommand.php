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

    protected $description = 'Merge split draft invoices per customer + marking + container scope (one invoice, many no_index)';

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
            ->select('invoices.user_id', 'barangs.id as barang_id')
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            $this->info('No draft invoice scopes found.');

            return self::SUCCESS;
        }

        $mixedInvoiceIds = Invoice::query()
            ->where('status', 'draft')
            ->when($userFilter !== null, static fn ($q) => $q->where('user_id', $userFilter))
            ->with(['items.barang.containers'])
            ->orderBy('id')
            ->get()
            ->filter(static function (Invoice $invoice): bool {
                $scopes = $invoice->items
                    ->map(static function ($item): string {
                        $barang = $item->barang;

                        return $barang !== null
                            ? InvoiceConsolidation::scopeKey($barang)
                            : '__missing__';
                    })
                    ->unique();

                return $scopes->count() > 1;
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        if ($mixedInvoiceIds->isNotEmpty()) {
            $this->line('Mixed-container draft invoices: '.$mixedInvoiceIds->implode(', '));
            if (! $dryRun) {
                foreach ($mixedInvoiceIds as $invoiceId) {
                    $invoice = Invoice::query()->with(['items.barang.containers'])->find($invoiceId);
                    if ($invoice !== null) {
                        InvoiceConsolidation::repairDraftConsolidationForInvoice($invoice);
                    }
                }
                $this->info('Split '.$mixedInvoiceIds->count().' mixed-container draft invoice(s).');
            } else {
                $this->info('Dry run: '.$mixedInvoiceIds->count().' mixed-container draft invoice(s) would be split.');
            }
        }

        $mergedGroups = 0;

        foreach ($pairs as $pair) {
            $userId = (int) $pair->user_id;
            $barangId = (int) $pair->barang_id;

            $anchor = Barang::query()
                ->with('containers')
                ->whereKey($barangId)
                ->where('user_id', $userId)
                ->first();

            if ($anchor === null) {
                continue;
            }

            $markingKey = InvoiceConsolidation::markingKey($anchor);
            $scopeKey = InvoiceConsolidation::scopeKey($anchor);
            $draftCount = Invoice::query()
                ->where('user_id', $userId)
                ->where('status', 'draft')
                ->with(['items.barang.containers'])
                ->get()
                ->filter(static fn (Invoice $invoice): bool => InvoiceConsolidation::invoiceMatchesBarangScope($invoice, $anchor))
                ->count();

            if ($draftCount <= 1) {
                continue;
            }

            $this->line(sprintf(
                'user_id=%d scope=%s (%s): %d draft invoice(s)',
                $userId,
                $scopeKey,
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
