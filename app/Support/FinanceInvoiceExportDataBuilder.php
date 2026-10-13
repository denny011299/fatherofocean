<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class FinanceInvoiceExportDataBuilder
{
    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<string, mixed>|null  $markingLookup
     * @return list<array<string, mixed>>
     */
    public static function build(Collection $invoices, ?Collection $markingLookup = null): array
    {
        return $invoices
            ->map(static fn (Invoice $invoice): array => self::forInvoice($invoice, $markingLookup))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, mixed>|null  $markingLookup
     * @return array<string, mixed>
     */
    public static function forInvoice(Invoice $invoice, ?Collection $markingLookup = null): array
    {
        $invoice->loadMissing([
            'customer',
            'items.barang.containers:id,partai,etd,departure_date',
            'payments',
        ]);

        $totalPaid = (float) $invoice->payments->where('status', 'approved')->sum('amount');
        $remaining = max(0, (float) $invoice->grand_total - $totalPaid);
        $pendingCount = (int) $invoice->payments->where('status', 'pending')->count();
        $status = InvoiceStatusPresenter::forInvoice($invoice, $totalPaid, $remaining, $pendingCount);

        $shipments = self::shipmentRows($invoice);
        $payments = self::paymentRows($invoice);
        $loadingDate = self::resolveLoadingDate($invoice);

        $shipmentCount = max(1, count($shipments));
        $pengiriman = $shipmentCount > 1
            ? (string) __('finance.consolidated_summary', ['count' => $shipmentCount])
            : '1 invoice · 1 pengiriman';

        return [
            'invoice_no' => (string) ($invoice->displayInvoiceNo() ?: '-'),
            'marking_label' => $invoice->resolvedMarkingLabel(),
            'customer_name' => trim((string) ($invoice->customer?->name ?? '')) ?: '-',
            'marketing_label' => $invoice->resolvedMarketingLabel($markingLookup),
            'partai_label' => $invoice->resolvedPartaiLabel(),
            'loading_date' => $loadingDate,
            'pengiriman' => $pengiriman,
            'due_date' => $invoice->due_date,
            'due_date_label' => InvoiceStatusPresenter::formatDueDate($invoice->due_date),
            'tanggal_lunas' => $invoice->resolvedPaidAt(),
            'tanggal_lunas_label' => InvoiceStatusPresenter::formatDueDate($invoice->resolvedPaidAt()),
            'status_label' => is_array($status) ? (string) ($status['label'] ?? '-') : (string) $status,
            'grand_total' => (float) $invoice->grand_total,
            'shipments' => $shipments,
            'payments' => $payments,
        ];
    }

    /**
     * @return list<array{no_index: string, total: float}>
     */
    private static function shipmentRows(Invoice $invoice): array
    {
        $items = $invoice->items
            ->filter(static fn (InvoiceItem $item): bool => $item->barang !== null)
            ->values();

        if ($items->isEmpty()) {
            return [[
                'no_index' => '-',
                'total' => (float) $invoice->grand_total,
            ]];
        }

        $grouped = $items
            ->groupBy(static fn (InvoiceItem $item): string => trim((string) ($item->barang?->no_index ?? '')) ?: '-')
            ->sortKeys();

        $rawTotals = [];
        foreach ($grouped as $noIndex => $groupItems) {
            $rawTotals[(string) $noIndex] = (float) $groupItems->sum(
                static fn (InvoiceItem $item): float => (float) $item->line_total,
            );
        }

        return self::allocateShipmentTotals($rawTotals, (float) $invoice->grand_total);
    }

    /**
     * Scale per-index line totals so they sum exactly to invoice grand total
     * (promo / claim gaps are absorbed proportionally, remainder on last row).
     *
     * @param  array<string, float>  $rawTotalsByIndex
     * @return list<array{no_index: string, total: float}>
     */
    public static function allocateShipmentTotals(array $rawTotalsByIndex, float $grandTotal): array
    {
        if ($rawTotalsByIndex === []) {
            return [['no_index' => '-', 'total' => $grandTotal]];
        }

        $sumRaw = array_sum($rawTotalsByIndex);
        $keys = array_keys($rawTotalsByIndex);

        if ($sumRaw <= 0.009) {
            $rows = [];
            foreach ($keys as $index => $noIndex) {
                $rows[] = [
                    'no_index' => (string) $noIndex,
                    'total' => $index === 0 ? $grandTotal : 0.0,
                ];
            }

            return $rows;
        }

        $allocated = 0.0;
        $lastKey = $keys[array_key_last($keys)];
        $rows = [];
        foreach ($keys as $noIndex) {
            if ($noIndex === $lastKey) {
                $rows[] = [
                    'no_index' => (string) $noIndex,
                    'total' => round($grandTotal - $allocated, 2),
                ];
                continue;
            }

            $amount = round($rawTotalsByIndex[$noIndex] * $grandTotal / $sumRaw, 2);
            $allocated += $amount;
            $rows[] = [
                'no_index' => (string) $noIndex,
                'total' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{amount: float, paid_at: ?Carbon}>
     */
    private static function paymentRows(Invoice $invoice): array
    {
        return $invoice->payments
            ->where('status', 'approved')
            ->sortBy(static function (Payment $payment): int {
                $at = $payment->reviewed_at ?? $payment->created_at;

                return $at instanceof Carbon ? $at->getTimestamp() : 0;
            })
            ->values()
            ->map(static function (Payment $payment): array {
                $at = $payment->reviewed_at ?? $payment->created_at;

                return [
                    'amount' => (float) $payment->amount,
                    'paid_at' => $at instanceof Carbon ? $at->copy() : ($at !== null ? Carbon::parse($at) : null),
                ];
            })
            ->all();
    }

    private static function resolveLoadingDate(Invoice $invoice): ?Carbon
    {
        $dates = [];
        foreach ($invoice->items as $item) {
            $barang = $item->barang;
            if ($barang === null) {
                continue;
            }

            foreach ($barang->containers ?? [] as $container) {
                foreach (['etd', 'departure_date'] as $field) {
                    $value = $container->{$field} ?? null;
                    if ($value instanceof Carbon) {
                        $dates[] = $value->copy()->startOfDay();
                    } elseif (is_string($value) && trim($value) !== '') {
                        $dates[] = Carbon::parse($value)->startOfDay();
                    }
                }
            }

            $tanggalMasuk = $barang->tanggal_masuk ?? null;
            if ($tanggalMasuk instanceof Carbon) {
                $dates[] = $tanggalMasuk->copy()->startOfDay();
            } elseif (is_string($tanggalMasuk) && trim($tanggalMasuk) !== '') {
                $dates[] = Carbon::parse($tanggalMasuk)->startOfDay();
            }
        }

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn (Carbon $a, Carbon $b): int => $a->timestamp <=> $b->timestamp);

        return $dates[0];
    }
}
