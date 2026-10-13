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
        // Identical container set only — intersect is too loose (mixed partai / dual-load).
        return self::containerIdsFor($left) === self::containerIdsFor($right);
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
        $scopeKey = self::scopeKey($barang);

        foreach ($invoice->items as $item) {
            $itemBarang = $item->barang;
            if ($itemBarang === null) {
                return false;
            }

            if (self::markingKey($itemBarang) !== $markingKey) {
                return false;
            }

            if (self::scopeKey($itemBarang) !== $scopeKey) {
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
     * Merge separate draft invoices for the same marking + customer + container scope (e.g. after container load).
     */
    public static function consolidateDraftsInContainer(Container $container, Barang $barang): void
    {
        $container->loadMissing(['barangs.containers']);

        $invoiceIds = DB::table('invoice_items')
            ->whereIn('barang_id', $container->barangs->modelKeys())
            ->distinct()
            ->pluck('invoice_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()->with(['items.barang.containers'])->find($invoiceId);
            if ($invoice !== null) {
                self::splitOpenInvoiceByContainerScope($invoice);
            }
        }

        $container->barangs
            ->filter(static fn (Barang $candidate): bool => $candidate->user_id !== null)
            ->unique(static fn (Barang $candidate): string => (int) $candidate->user_id.'|'.self::scopeKey($candidate))
            ->each(static function (Barang $candidate): void {
                self::consolidateOpenDraftsForBarangScope($candidate);
            });
    }

    /**
     * Merge open draft invoices for the same customer + marking + container scope (one invoice, many no_index).
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

        self::mergeOpenDraftInvoicesForScope((int) $barang->user_id, self::scopeKey($barang));
    }

    /** Repair legacy split/mixed drafts before finance UI / PDF render. */
    public static function repairDraftConsolidationForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items.barang.containers');
        if ($invoice->items->isEmpty()) {
            return;
        }

        $splitIds = self::splitOpenInvoiceByContainerScope($invoice);
        foreach ($splitIds as $invoiceId) {
            $fresh = Invoice::query()->with(['items.barang.containers'])->find($invoiceId);
            $anchor = $fresh?->items->first()?->barang;
            if ($anchor === null) {
                continue;
            }
            self::consolidateOpenDraftsForBarangScope($anchor);
        }
    }

    /**
     * Split open invoice that mixes different container scopes (e.g. partai "601, 502")
     * into one invoice per marking+container set.
     *
     * @return list<int> surviving invoice ids
     */
    public static function splitOpenInvoiceByContainerScope(Invoice $invoice): array
    {
        if (! self::isInvoiceOpenForMoreItems($invoice)) {
            return [(int) $invoice->id];
        }

        $lockKey = sprintf('invoice-consolidation-split:%d', (int) $invoice->id);

        return Cache::lock($lockKey, 30)->block(15, static function () use ($invoice): array {
            $locked = Invoice::query()
                ->with(['items.barang.containers'])
                ->find($invoice->id);

            if ($locked === null || ! self::isInvoiceOpenForMoreItems($locked)) {
                return $invoice->id ? [(int) $invoice->id] : [];
            }

            $groups = $locked->items
                ->groupBy(static function (InvoiceItem $item): string {
                    $barang = $item->barang;
                    if ($barang === null) {
                        return '__missing__';
                    }

                    return self::scopeKey($barang);
                })
                ->sortByDesc(static fn (Collection $group): int => $group->count());

            if ($groups->count() <= 1) {
                return [(int) $locked->id];
            }

            $groups->shift(); // keep largest group on the original invoice
            $resultIds = [(int) $locked->id];

            foreach ($groups as $moveItems) {
                /** @var Collection<int, InvoiceItem> $moveItems */
                $newInvoiceId = self::createOpenInvoiceShellFrom($locked, $moveItems);
                if ($newInvoiceId === null) {
                    continue;
                }

                $itemIds = $moveItems
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                DB::table('invoice_items')
                    ->whereIn('id', $itemIds)
                    ->update([
                        'invoice_id' => $newInvoiceId,
                        'updated_at' => now(),
                    ]);

                $resultIds[] = $newInvoiceId;
            }

            foreach ($resultIds as $invoiceId) {
                $fresh = Invoice::query()->with(['items.barang'])->find($invoiceId);
                if ($fresh === null) {
                    continue;
                }
                self::syncInvoiceNumber($fresh);
                $fresh->recalculate();
            }

            return $resultIds;
        });
    }

    /**
     * @param  Collection<int, InvoiceItem>  $seedItems
     */
    private static function createOpenInvoiceShellFrom(Invoice $source, Collection $seedItems): ?int
    {
        $firstBarang = $seedItems->first()?->barang;
        if ($firstBarang === null || ! $source->user_id) {
            return null;
        }

        $now = now();
        $pricePerCbm = (float) ($firstBarang->price_per_cbm ?? $source->price_per_cbm ?? 0);

        return (int) DB::table('invoices')->insertGetId([
            'invoice_no' => Invoice::generateNumber(
                $seedItems->map(static fn (InvoiceItem $item) => $item->barang)->filter()->values(),
            ),
            'user_id' => $source->user_id,
            'price_per_cbm' => $pricePerCbm,
            'status' => $source->status,
            'due_date' => $source->due_date?->format('Y-m-d') ?? $source->getRawOriginal('due_date'),
            'created_by' => $source->created_by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function mergeOpenDraftInvoicesForScope(int $userId, string $scopeKey): void
    {
        $lockKey = sprintf('invoice-consolidation-merge:%d:%s', $userId, $scopeKey);

        Cache::lock($lockKey, 30)->block(15, static function () use ($userId, $scopeKey): void {
            $invoiceIds = Invoice::query()
                ->where('user_id', $userId)
                ->where('status', 'draft')
                ->notFullyPaid()
                ->with(['items.barang.containers'])
                ->orderBy('id')
                ->get()
                ->filter(static function (Invoice $invoice) use ($scopeKey): bool {
                    if ($invoice->items->isEmpty()) {
                        return false;
                    }

                    foreach ($invoice->items as $item) {
                        $itemBarang = $item->barang;
                        if ($itemBarang === null) {
                            return false;
                        }

                        if (self::scopeKey($itemBarang) !== $scopeKey) {
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
