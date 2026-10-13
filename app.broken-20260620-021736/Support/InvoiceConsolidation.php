<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class InvoiceConsolidation
{
    public static function markingKey(Barang $barang): string
    {
        return SuratJalanPdfData::markingKey($barang);
    }

    /**
     * @return list<int>
     */
    public static function containerIdsFor(Barang $barang): array
    {
        if ($barang->relationLoaded('containers')) {
            return $barang->containers
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        if (! $barang->exists) {
            return [];
        }

        return $barang->containers()
            ->pluck('containers.id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public static function scopeKey(Barang $barang): string
    {
        $containers = self::containerIdsFor($barang);
        $containerPart = $containers === [] ? '_' : implode(',', $containers);

        return self::markingKey($barang) . '|' . $containerPart;
    }

    public static function sharesContainerScope(Barang $left, Barang $right): bool
    {
        $leftIds = self::containerIdsFor($left);
        $rightIds = self::containerIdsFor($right);

        if ($leftIds === [] || $rightIds === []) {
            return true;
        }

        return array_intersect($leftIds, $rightIds) !== [];
    }

    public static function findDraftInvoiceForBarang(Barang $barang): ?Invoice
    {
        return self::findOpenInvoiceForBarang($barang);
    }

    /** Draft atau sent yang belum lunas — boleh ditambah item (bukan invoice sudah dibayar). */
    public static function findOpenInvoiceForBarang(Barang $barang): ?Invoice
    {
        if (! $barang->user_id) {
            return null;
        }

        $candidates = Invoice::query()
            ->where('user_id', $barang->user_id)
            ->whereIn('status', ['draft', 'sent'])
            ->notFullyPaid()
            ->whereHas('items', static fn ($query) => $query->where('barang_id', '!=', $barang->id))
            ->with(['items.barang.containers'])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $invoice) {
            if (self::invoiceMatchesBarangScope($invoice, $barang)) {
                return $invoice;
            }
        }

        return null;
    }

    public static function isInvoiceOpenForMoreItems(Invoice $invoice): bool
    {
        if (! in_array($invoice->status, ['draft', 'sent'], true)) {
            return false;
        }

        return Invoice::query()
            ->whereKey($invoice->id)
            ->notFullyPaid()
            ->exists();
    }

    public static function invoiceMatchesBarangScope(Invoice $invoice, Barang $barang): bool
    {
        if ($invoice->items->isEmpty()) {
            return false;
        }

        $markingKey = self::markingKey($barang);

        foreach ($invoice->items as $item) {
            $itemBarang = $item->barang;
            if ($itemBarang === null) {
                return false;
            }

            if (self::markingKey($itemBarang) !== $markingKey) {
                return false;
            }

            if (! self::sharesContainerScope($barang, $itemBarang)) {
                return false;
            }
        }

        return true;
    }

    public static function attachBarangToInvoice(Invoice $invoice, Barang $barang): Invoice
    {
        return DB::transaction(function () use ($invoice, $barang): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $barang = Barang::query()->findOrFail($barang->id);

            $existingInvoiceId = DB::table('invoice_items')
                ->where('barang_id', $barang->id)
                ->value('invoice_id');

            if ($existingInvoiceId !== null) {
                return Invoice::query()->findOrFail((int) $existingInvoiceId);
            }

            $pricePerCbm = (float) ($barang->price_per_cbm ?? 0);
            $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
            $extra = (float) ($barang->extra_charge ?? 0);
            $now = now();

            DB::table('invoice_items')->insert([
                'invoice_id' => $invoice->id,
                'barang_id' => $barang->id,
                'cbm' => $barang->total_cbm,
                'price_per_cbm' => $pricePerCbm,
                'insurance' => $insuranceAmount,
                'extra' => $extra,
                'line_total' => BarangShippingPricing::syncInvoiceItemLineTotal(
                    (float) $barang->total_cbm,
                    $pricePerCbm,
                    $insuranceAmount,
                    $extra,
                    $barang,
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $invoice = $invoice->fresh(['items.barang']);
            self::syncInvoiceNumber($invoice);
            $invoice->recalculate();

            return $invoice->fresh(['items']);
        });
    }

    public static function syncInvoiceNumber(Invoice $invoice): void
    {
        $invoice->loadMissing('items.barang');

        $barangs = $invoice->items
            ->map(static fn (InvoiceItem $item) => $item->barang)
            ->filter()
            ->values();

        if ($barangs->isEmpty()) {
            return;
        }

        $newNo = Invoice::generateNumber($barangs, $invoice->id);
        if ($newNo === $invoice->invoice_no) {
            return;
        }

        DB::table('invoices')->where('id', $invoice->id)->update([
            'invoice_no' => $newNo,
            'updated_at' => now(),
        ]);

        $invoice->invoice_no = $newNo;
    }

    /**
     * Merge separate draft invoices for the same marking + customer (e.g. after container load).
     */
    public static function consolidateDraftsInContainer(Container $container, Barang $barang): void
    {
        $container->loadMissing(['barangs.containers']);

        $container->barangs
            ->filter(static fn (Barang $candidate): bool => $candidate->user_id !== null)
            ->unique(static fn (Barang $candidate): string => (int) $candidate->user_id.'|'.self::markingKey($candidate))
            ->each(static function (Barang $candidate): void {
                self::consolidateOpenDraftsForBarangScope($candidate);
            });
    }

    /**
     * Merge all open draft invoices for the same customer + marking (one invoice, many no_index).
     */
    public static function consolidateOpenDraftsForBarangScope(Barang $barang): void
    {
        if (! $barang->user_id) {
            return;
        }

        $markingKey = self::markingKey($barang);
        if ($markingKey === '' || $markingKey === SuratJalanPdfData::NO_MARKING_KEY) {
            return;
        }

        self::mergeOpenDraftInvoicesForUserMarking((int) $barang->user_id, $markingKey);
    }

    /** Repair legacy split drafts before finance UI / PDF render. */
    public static function repairDraftConsolidationForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items.barang.containers');
        $anchor = $invoice->items->first()?->barang;
        if ($anchor === null) {
            return;
        }

        self::consolidateOpenDraftsForBarangScope($anchor);
    }

    private static function mergeOpenDraftInvoicesForUserMarking(int $userId, string $markingKey): void
    {
        $lockKey = sprintf('invoice-consolidation-merge:%d:%s', $userId, $markingKey);

        Cache::lock($lockKey, 30)->block(15, static function () use ($userId, $markingKey): void {
            $invoiceIds = Invoice::query()
                ->where('user_id', $userId)
                ->where('status', 'draft')
                ->notFullyPaid()
                ->with(['items.barang.containers'])
                ->orderBy('id')
                ->get()
                ->filter(static function (Invoice $invoice) use ($markingKey): bool {
                    if ($invoice->items->isEmpty()) {
                        return false;
                    }

                    foreach ($invoice->items as $item) {
                        $itemBarang = $item->barang;
                        if ($itemBarang === null) {
                            return false;
                        }

                        if (self::markingKey($itemBarang) !== $markingKey) {
                            return false;
                        }
                    }

                    return true;
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            if ($invoiceIds->count() <= 1) {
                return;
            }

            $target = Invoice::query()->with(['items.barang.containers'])->find($invoiceIds->first());
            if ($target === null) {
                return;
            }

            foreach ($invoiceIds->slice(1) as $sourceId) {
                $source = Invoice::query()->with(['items.barang.containers'])->find($sourceId);
                if ($source === null || $source->id === $target->id) {
                    continue;
                }

                if ($source->status !== 'draft' || $target->status !== 'draft') {
                    continue;
                }

                self::mergeDraftInvoiceInto($target, $source);
                $target = $target->fresh(['items.barang.containers']);
            }

            if ($target !== null) {
                self::syncInvoiceNumber($target);
                $target->recalculate();
            }
        });
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return Collection<string, Collection<int, Barang>>
     */
    public static function groupBarangsByScope(Collection $barangs): Collection
    {
        return $barangs->groupBy(static fn (Barang $barang): string => self::scopeKey($barang));
    }

    private static function mergeDraftInvoiceInto(Invoice $target, Invoice $source): void
    {
        DB::transaction(function () use ($target, $source): void {
            $lockedTarget = Invoice::query()->lockForUpdate()->findOrFail($target->id);
            $lockedSource = Invoice::query()->lockForUpdate()->findOrFail($source->id);

            if ($lockedSource->status !== 'draft' || $lockedTarget->status !== 'draft') {
                return;
            }

            DB::table('invoice_items')
                ->where('invoice_id', $lockedSource->id)
                ->update([
                    'invoice_id' => $lockedTarget->id,
                    'updated_at' => now(),
                ]);

            DB::table('invoices')->where('id', $lockedSource->id)->delete();
        });
    }
}
