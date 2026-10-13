<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BarangShippingPricing;
use App\Support\CbmFormat;
use App\Support\DatePresetFilter;
use App\Support\InvoiceStatusPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Invoice extends Model
{
    /** Max length stored in `invoices.invoice_no` (DB column). */
    public const INVOICE_NO_COLUMN_MAX_LENGTH = 500;

    /** Max length for generated display number before uniqueness suffix. */
    public const INVOICE_NO_DISPLAY_MAX_LENGTH = 250;

    protected $fillable = [
        'invoice_no',
        'user_id',
        'total_cbm',
        'price_per_cbm',
        'subtotal',
        'insurance_total',
        'extra_charges',
        'promo_id',
        'promo_name',
        'promo_type',
        'promo_persen',
        'promo_amount_rp',
        'promo_discount',
        'claim_deduction_total',
        'grand_total',
        'status',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total_cbm' => 'decimal:4',
            'price_per_cbm' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'insurance_total' => 'decimal:2',
            'extra_charges' => 'decimal:2',
            'promo_persen' => 'decimal:2',
            'promo_amount_rp' => 'decimal:2',
            'promo_discount' => 'decimal:2',
            'claim_deduction_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(MasterPromo::class, 'promo_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /** Jumlah pengiriman unik (no_index) pada invoice — selaras InvoicePdfData / PDF sections. */
    public function distinctShipmentCount(): int
    {
        $this->loadMissing('items.barang');

        return (int) $this->items
            ->map(static fn (InvoiceItem $item): string => trim((string) ($item->barang?->no_index ?? '')) ?: '-')
            ->unique()
            ->count();
    }

    public function isMultiShipment(): bool
    {
        return $this->distinctShipmentCount() > 1;
    }

    public function consolidatedSummary(): ?string
    {
        $count = $this->distinctShipmentCount();

        return $count > 1
            ? __('finance.consolidated_summary', ['count' => $count])
            : null;
    }

    /** Total tagihan setelah diskon promo, sebelum potongan klaim disetujui. */
    public function totalBeforeClaims(): float
    {
        return max(0, (float) $this->grand_total + (float) ($this->claim_deduction_total ?? 0));
    }

    /** Nomor marking dari item barang (unik); fallback kode user invoice. */
    public function resolvedMarkingLabel(): string
    {
        $this->loadMissing('items.barang', 'customer');

        $codes = $this->items
            ->map(static fn (InvoiceItem $item) => Marking::resolvedCodeForBarangValue(
                (string) ($item->barang?->marking ?? ''),
                (string) ($item->barang?->customer_name ?? ''),
            ))
            ->filter(static fn (string $value) => $value !== '')
            ->unique()
            ->values();

        if ($codes->isNotEmpty()) {
            return $codes->implode(', ');
        }

        $fromUser = trim((string) ($this->customer?->marking_code ?? ''));
        if ($fromUser !== '') {
            return $fromUser;
        }

        $fromMarking = Marking::findForBarangValue(
            '',
            trim((string) ($this->customer?->name ?? '')),
        );

        return $fromMarking !== null ? $fromMarking->displayCode() : '-';
    }

    /** Partai unik dari container barang pada item invoice. */
    public function resolvedPartaiLabel(): string
    {
        $this->loadMissing('items.barang.containers');

        $partais = $this->items
            ->flatMap(static fn (InvoiceItem $item) => $item->barang?->containers ?? collect())
            ->pluck('partai')
            ->map(static fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $partais->isNotEmpty() ? $partais->implode(', ') : '-';
    }

    /** Nama sales/marketing dari marking item barang (unik); fallback marking customer invoice. */
    public function resolvedMarketingLabel(?Collection $markingLookup = null): string
    {
        $this->loadMissing('items.barang', 'customer');

        $names = $this->items
            ->map(function (InvoiceItem $item) use ($markingLookup): ?string {
                $markingValue = trim((string) ($item->barang?->marking ?? ''));
                if ($markingValue === '') {
                    return null;
                }

                $marking = $markingLookup?->get($markingValue);
                if ($marking === null) {
                    $marking = Marking::findForBarangValue(
                        $markingValue,
                        (string) ($item->barang?->customer_name ?? ''),
                    );
                }

                $name = trim((string) ($marking?->sales?->name ?? ''));

                return $name !== '' ? $name : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($names->isNotEmpty()) {
            return $names->implode(', ');
        }

        $fromUser = trim((string) ($this->customer?->marking_code ?? ''));
        if ($fromUser !== '') {
            $marking = $markingLookup?->get($fromUser)
                ?? Marking::query()->whereBarangMarkingValue($fromUser)->with('sales:id,name')->first();
            $name = trim((string) ($marking?->sales?->name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $fromMarking = Marking::findForBarangValue(
            '',
            trim((string) ($this->customer?->name ?? '')),
        );
        if ($fromMarking !== null) {
            $fromMarking->loadMissing('sales:id,name');
            $name = trim((string) ($fromMarking->sales?->name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '-';
    }

    /**
     * Tanggal lunas: waktu approval pembayaran terakhir yang disetujui,
     * hanya jika total pembayaran approved menutup grand_total.
     */
    public function resolvedPaidAt(): ?Carbon
    {
        $this->loadMissing('payments');

        $totalPaid = (float) $this->payments->where('status', 'approved')->sum('amount');
        $remaining = max(0, (float) $this->grand_total - $totalPaid);

        if (! InvoiceStatusPresenter::isFullyPaid($totalPaid, $remaining)) {
            return null;
        }

        $latest = $this->payments
            ->where('status', 'approved')
            ->map(static fn (Payment $payment): ?Carbon => $payment->reviewed_at ?? $payment->created_at)
            ->filter()
            ->max();

        return $latest instanceof Carbon ? $latest : null;
    }

    public function scopeFilterByPaidDatePreset(
        Builder $query,
        ?string $preset,
        ?string $from = null,
        ?string $to = null,
    ): Builder {
        $preset = DatePresetFilter::normalizePreset($preset);
        if ($preset === '' && (($from ?? '') !== '' || ($to ?? '') !== '')) {
            $preset = 'custom';
        }

        [$fromDate, $toDate] = DatePresetFilter::resolveBounds($preset, $from, $to);

        if ($fromDate === null && $toDate === null) {
            return $query;
        }

        $query->fullyPaid();

        $paidAtSql = '(SELECT MAX(COALESCE(payments.reviewed_at, payments.created_at))
            FROM payments
            WHERE payments.invoice_id = invoices.id
            AND payments.status = ?)';

        if ($fromDate !== null) {
            $query->whereRaw($paidAtSql.' >= ?', ['approved', $fromDate->copy()->startOfDay()]);
        }

        if ($toDate !== null) {
            $query->whereRaw($paidAtSql.' <= ?', ['approved', $toDate->copy()->endOfDay()]);
        }

        return $query;
    }

    public function refreshTotalsFromBarangIfNeeded(): void
    {
        $this->loadMissing('items.barang.additionalPrices');

        if ($this->items->isEmpty()) {
            return;
        }

        $expectedBeforeDiscount = $this->items->sum(
            fn ($item) => $item->barang
                ? BarangShippingPricing::syncInvoiceItemLineTotal(
                    (float) $item->cbm,
                    (float) $item->price_per_cbm,
                    (float) $item->insurance,
                    (float) $item->extra,
                    $item->barang,
                )
                : (float) $item->line_total,
        ) + $this->additionalTotalFromItems($this->items);

        $expectedPromoDiscount = $this->calculatePromoDiscount($expectedBeforeDiscount);
        $expectedClaimDeduction = (float) $this->claims()
            ->where('status', Claim::STATUS_APPROVED)
            ->sum('amount');
        $expectedGrandTotal = max(0, $expectedBeforeDiscount - $expectedPromoDiscount - $expectedClaimDeduction);

        if (
            abs($expectedGrandTotal - (float) $this->grand_total) < 0.01
            && abs($expectedClaimDeduction - (float) $this->claim_deduction_total) < 0.01
            && (float) $this->grand_total >= 0
        ) {
            return;
        }

        foreach ($this->items as $item) {
            if ($item->barang === null) {
                continue;
            }
            $item->save();
        }

        $this->recalculate();
    }

    public function recalculate(): void
    {
        $items = $this->items()->with('barang.additionalPrices')->get();
        $additionalTotal = $this->additionalTotalFromItems($items);
        $this->total_cbm = CbmFormat::truncate((float) $items->sum('cbm'));
        $this->insurance_total = $items->sum('insurance');
        $this->extra_charges = $items->sum('extra');
        $this->subtotal = $items->sum(
            fn ($i) => max(0, (float) $i->line_total - (float) $i->insurance - (float) $i->extra),
        );

        $beforeDiscount = (float) $this->subtotal
            + (float) $this->insurance_total
            + (float) $this->extra_charges
            + $additionalTotal;
        $this->promo_discount = $this->calculatePromoDiscount($beforeDiscount);

        $this->claim_deduction_total = (float) $this->claims()
            ->where('status', Claim::STATUS_APPROVED)
            ->sum('amount');
        $this->grand_total = max(0, $beforeDiscount - (float) $this->promo_discount - (float) $this->claim_deduction_total);
        $this->save();
    }

    public function promoValueLabel(): string
    {
        if (($this->promo_type ?? MasterPromo::TYPE_PERCENT) === MasterPromo::TYPE_FIXED_RP) {
            return 'Rp '.number_format((float) ($this->promo_amount_rp ?? 0), 0, ',', '.');
        }

        return number_format((float) ($this->promo_persen ?? 0), 2, ',', '.').'%';
    }

    public function calculatePromoDiscount(float $beforeDiscount): float
    {
        if (($this->promo_type ?? MasterPromo::TYPE_PERCENT) === MasterPromo::TYPE_FIXED_RP) {
            $amount = (float) ($this->promo_amount_rp ?? 0);

            return $amount > 0 ? min($amount, $beforeDiscount) : 0.0;
        }

        $persen = (float) ($this->promo_persen ?? 0);

        return $persen > 0 ? round($beforeDiscount * ($persen / 100), 2) : 0.0;
    }

    private function additionalTotalFromItems(iterable $items): float
    {
        $barangs = collect($items)
            ->map(static fn ($item) => $item->barang)
            ->filter()
            ->unique('id');

        return (float) $barangs->sum(
            static fn ($barang) => $barang->additionalPrices->sum(
                static fn ($row) => (float) ($row->price ?? 0),
            ),
        );
    }

    public function scopeNotFullyPaid(Builder $query): Builder
    {
        return $query->whereRaw(
            'grand_total > (
                SELECT COALESCE(SUM(amount), 0)
                FROM payments
                WHERE payments.invoice_id = invoices.id
                AND payments.status = ?
            )',
            ['approved'],
        );
    }

    public function scopeFullyPaid(Builder $query): Builder
    {
        return $query
            ->whereRaw(
                'grand_total <= (
                    SELECT COALESCE(SUM(amount), 0)
                    FROM payments
                    WHERE payments.invoice_id = invoices.id
                    AND payments.status = ?
                )',
                ['approved'],
            )
            ->whereRaw(
                '(
                    SELECT COALESCE(SUM(amount), 0)
                    FROM payments
                    WHERE payments.invoice_id = invoices.id
                    AND payments.status = ?
                ) > 0',
                ['approved'],
            );
    }

    public function scopePastDue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());
    }

    /**
     * Format: INV-{no_index} — nomor invoice mengikuti index / no urut pengiriman (barangs.no_index).
     *
     * @param  iterable<Barang|string|null>  $shippingIndexes
     */
    public static function generateNumber(iterable $shippingIndexes = [], ?int $excludeInvoiceId = null): string
    {
        return DB::transaction(function () use ($shippingIndexes, $excludeInvoiceId) {
            DB::table('invoices')->lockForUpdate()->max('id');

            $indexes = self::normalizeShippingIndexes($shippingIndexes);

            return self::ensureUniqueInvoiceNo(
                self::formatInvoiceNumber($indexes),
                $excludeInvoiceId,
            );
        });
    }

    /**
     * Build INV-{indexes} or compact INV-{first}+{n}pengiriman when too long.
     *
     * @param  Collection<int, string>  $indexes
     */
    public static function formatInvoiceNumber(Collection $indexes): string
    {
        if ($indexes->isEmpty()) {
            throw new \InvalidArgumentException('Invoice number requires at least one shipping index (no_index).');
        }

        if ($indexes->count() === 1) {
            return 'INV-' . $indexes->first();
        }

        $prefix = 'INV-';
        $joined = $prefix . $indexes->implode('+');

        if (strlen($joined) <= self::INVOICE_NO_DISPLAY_MAX_LENGTH) {
            return $joined;
        }

        $extraCount = $indexes->count() - 1;

        return $prefix . $indexes->first() . '+' . $extraCount . 'pengiriman';
    }

    /** @param  iterable<Barang|string|null>  $shippingIndexes */
    public static function normalizeShippingIndexes(iterable $shippingIndexes): Collection
    {
        return collect($shippingIndexes)
            ->map(static function (mixed $value): string {
                if ($value instanceof Barang) {
                    return trim((string) ($value->no_index ?? ''));
                }

                return trim((string) ($value ?? ''));
            })
            ->filter(static fn (string $index): bool => $index !== '')
            ->map(static fn (string $index): string => (string) preg_replace('/\s+/', '', $index))
            ->unique()
            ->sort()
            ->values();
    }

    private static function ensureUniqueInvoiceNo(string $candidate, ?int $excludeInvoiceId = null): string
    {
        $candidate = Str::limit($candidate, self::INVOICE_NO_COLUMN_MAX_LENGTH, '');
        if ($candidate === '') {
            throw new \InvalidArgumentException('Invoice number candidate is empty.');
        }

        $exists = static fn (string $no): bool => DB::table('invoices')
            ->where('invoice_no', $no)
            ->when(
                $excludeInvoiceId !== null,
                static fn ($query) => $query->where('id', '!=', $excludeInvoiceId),
            )
            ->exists();

        if (! $exists($candidate)) {
            return $candidate;
        }

        $suffix = 2;
        while ($exists($candidate . '-' . $suffix)) {
            $suffix++;
        }

        return Str::limit(
            $candidate . '-' . $suffix,
            self::INVOICE_NO_COLUMN_MAX_LENGTH,
            '',
        );
    }
}
