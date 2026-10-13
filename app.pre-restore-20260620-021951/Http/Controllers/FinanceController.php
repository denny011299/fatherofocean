<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\FinanceInvoiceListExport;
use App\Exports\InvoiceBlankTemplateExport;
use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Marking;
use App\Models\MasterPromo;
use App\Models\User;
use App\Support\BarangShippingPricing;
use App\Support\InvoiceConsolidation;
use App\Support\InvoiceDueResolver;
use App\Support\InvoicePdfData;
use App\Support\InvoiceStatusPresenter;
use App\Support\ShipmentEarningsSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $invoices = $this->invoiceListQuery($request)
            ->latest()
            ->with(['customer', 'items.barang.containers:id,partai', 'payments.reviewer'])
            ->get();
        $invoices->each(static fn (Invoice $invoice) => $invoice->refreshTotalsFromBarangIfNeeded());

        $markingLookup = $this->markingLookupForInvoices($invoices);

        $customers = User::role('customer')->get();

        $stats = $this->invoiceStats();

        $cardFilter = $this->resolveInvoiceCardFilter($request);
        $activeCard = is_string($cardFilter) ? $cardFilter : null;

        $user = auth()->user();
        $isSalesScoped = Marking::userHasSalesMarkingScope($user);
        $isSupervisorScoped = Marking::userHasSupervisorMarkingScope($user);
        $salesUsersQuery = User::query()->marketingStaff()->orderBy('name');
        if ($isSalesScoped) {
            $salesUsersQuery->where('id', $user->id);
        } elseif ($isSupervisorScoped) {
            $salesUsersQuery->whereIn('id', Marking::teamScopeUserIds($user));
        }
        $salesUsers = $salesUsersQuery->get(['id', 'name']);

        return view('finance.index', compact(
            'invoices',
            'customers',
            'stats',
            'activeCard',
            'salesUsers',
            'isSalesScoped',
            'isSupervisorScoped',
            'markingLookup',
        ));
    }

    public function exportIndex(Request $request): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('view invoice'), 403);

        $invoices = $this->invoiceListQuery($request)
            ->latest()
            ->with(['customer', 'items.barang.containers:id,partai', 'payments'])
            ->get();
        $invoices->each(static fn (Invoice $invoice) => $invoice->refreshTotalsFromBarangIfNeeded());

        $markingLookup = $this->markingLookupForInvoices($invoices);
        $rows = $invoices->map(fn (Invoice $invoice) => $this->formatInvoiceListRow($invoice, $markingLookup))->values()->all();

        $filename = 'invoice-pembayaran-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new FinanceInvoiceListExport($rows), $filename);
    }

    public function indexData(Request $request): JsonResponse
    {
        $invoices = $this->invoiceListQuery($request)
            ->latest()
            ->with(['customer', 'items.barang.containers:id,partai', 'payments.reviewer'])
            ->get();
        $invoices->each(static fn (Invoice $invoice) => $invoice->refreshTotalsFromBarangIfNeeded());

        $markingLookup = $this->markingLookupForInvoices($invoices);
        $activeCard = $this->resolveInvoiceCardFilter($request);

        return response()->json([
            'stats' => $this->invoiceStats(),
            'active_card' => is_string($activeCard) ? $activeCard : '',
            'rows' => $invoices->map(fn (Invoice $invoice) => $this->formatInvoiceListRow($invoice, $markingLookup))->values(),
        ]);
    }

    /** @param EloquentCollection<int, Invoice> $invoices */
    private function markingLookupForInvoices(EloquentCollection $invoices): Collection
    {
        $values = $invoices
            ->flatMap(static fn (Invoice $invoice) => $invoice->items->map(
                static fn (InvoiceItem $item) => $item->barang?->marking,
            ))
            ->merge($invoices->map(static fn (Invoice $invoice) => $invoice->customer?->marking_code));

        return Marking::lookupForBarangValues($values);
    }

    private function invoiceListQuery(Request $request): Builder
    {
        $query = Invoice::query();

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('marking_code', 'like', "%{$search}%"))
                ->orWhereHas('items.barang', fn ($b) => $b->where('marking', 'like', "%{$search}%")));
        }

        $cardFilter = $this->resolveInvoiceCardFilter($request);

        match ($cardFilter) {
            'unpaid' => $query->notFullyPaid(),
            'overdue' => $query->notFullyPaid()->pastDue(),
            'paid' => $query->fullyPaid(),
            default => null,
        };

        if ($cardFilter === null && $status = $request->get('status')) {
            $query->where('status', $status);
        }

        $query->filterByPaidDatePreset(
            $request->input('paid_date_preset'),
            $request->input('paid_date_from'),
            $request->input('paid_date_to'),
        );

        $user = auth()->user();
        if (Marking::userHasSalesMarkingScope($user)) {
            $this->applyInvoiceSalesIdFilter($query, (int) $user->id);
        } elseif (Marking::userHasSupervisorMarkingScope($user)) {
            $salesFilterId = $request->input('sales_id');
            if ($salesFilterId !== null && $salesFilterId !== '') {
                $filterId = (int) $salesFilterId;
                if (in_array($filterId, Marking::teamScopeUserIds($user), true)) {
                    $this->applyInvoiceSalesIdFilter($query, $filterId);
                } else {
                    Marking::restrictInvoicesQuery($query, $user);
                }
            } else {
                Marking::restrictInvoicesQuery($query, $user);
            }
        } elseif (($salesFilterId = $request->input('sales_id')) !== null && $salesFilterId !== '') {
            $this->applyInvoiceSalesIdFilter($query, (int) $salesFilterId);
        }

        return $query;
    }

    /** @return array{total: int, unpaid: int, overdue: int, paid: int} */
    private function invoiceStats(): array
    {
        $user = auth()->user();
        $scopeKey = $user === null
            ? 'guest'
            : (Marking::userHasTeamMarkingScope($user) ? 'team_'.$user->id : 'user_'.$user->id);

        return Cache::remember('finance_invoice_stats:'.$scopeKey, 60, function (): array {
            $base = Invoice::query();
            Marking::restrictInvoicesQuery($base);

            return [
                'total' => (clone $base)->count(),
                'unpaid' => (clone $base)->notFullyPaid()->count(),
                'overdue' => (clone $base)->notFullyPaid()->pastDue()->count(),
                'paid' => (clone $base)->fullyPaid()->count(),
            ];
        });
    }

    /** @param Builder<Invoice> $query */
    private function applyInvoiceSalesIdFilter(Builder $query, int $salesId): void
    {
        $whereSql = Marking::barangToMarkingWhereSql('markings', 'barangs');
        if ($whereSql === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereHas('items.barang', function (Builder $barangQuery) use ($whereSql, $salesId): void {
            $barangQuery->whereExists(function ($sub) use ($whereSql, $salesId): void {
                $sub->selectRaw('1')
                    ->from('markings')
                    ->whereRaw($whereSql)
                    ->where('markings.sales_id', $salesId);
            });
        });
    }

    private function resolveInvoiceCardFilter(Request $request): ?string
    {
        $cardFilter = $request->get('card');
        if ($request->boolean('unpaid') && $cardFilter === null) {
            $cardFilter = 'unpaid';
        }

        return is_string($cardFilter) && $cardFilter !== '' ? $cardFilter : null;
    }

    /** @return array<string, mixed> */
    private function formatInvoiceListRow(Invoice $invoice, ?Collection $markingLookup = null): array
    {
        $totalPaid = (float) $invoice->payments->where('status', 'approved')->sum('amount');
        $remaining = max(0, (float) $invoice->grand_total - $totalPaid);
        $pendingCount = (int) $invoice->payments->where('status', 'pending')->count();
        $status = InvoiceStatusPresenter::forInvoice($invoice, $totalPaid, $remaining, $pendingCount);
        $shipmentCount = $invoice->distinctShipmentCount();
        $isConsolidated = $invoice->isMultiShipment();

        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'marking_label' => $invoice->resolvedMarkingLabel(),
            'marketing_label' => $invoice->resolvedMarketingLabel($markingLookup),
            'partai_label' => $invoice->resolvedPartaiLabel(),
            'customer_name' => trim((string) ($invoice->customer?->name ?? '')) ?: '-',
            'grand_total' => (float) $invoice->grand_total,
            'total_before_claims' => $invoice->totalBeforeClaims(),
            'total_claim_amount' => (float) ($invoice->claim_deduction_total ?? 0),
            'total_paid' => $totalPaid,
            'remaining' => $remaining,
            'due_date' => InvoiceStatusPresenter::formatDueDate($invoice->due_date),
            'tanggal_lunas' => InvoiceStatusPresenter::formatDueDate($invoice->resolvedPaidAt()),
            'status' => $status,
            'shipment_count' => $shipmentCount,
            'is_consolidated' => $isConsolidated,
            'is_multi_shipment' => $isConsolidated,
            'consolidated_summary' => $invoice->consolidatedSummary(),
        ];
    }

    public function pricing(Request $request): View
    {
        abort_unless(auth()->user()?->can('view pricing')
            || $this->canViewPengajuanTab(), 403);

        return view('finance.pricing', $this->pricingPageData($request));
    }

    /** @return array<string, mixed> */
    private function pricingPageData(Request $request): array
    {
        $user = auth()->user();
        $pengajuanBadge = 0;

        if ($user?->can('approve repricing')) {
            $badgeQuery = \App\Models\RepricingRequest::query()
                ->where('status', \App\Models\RepricingRequest::STATUS_PENDING);
            if (Marking::userHasTeamMarkingScope($user)) {
                $badgeQuery->whereHas(
                    'barang',
                    static fn (Builder $barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
                );
            }
            $pengajuanBadge = $badgeQuery->count();
        } elseif ($user?->can('submit repricing') || $user?->can('change price')) {
            $pengajuanBadge = \App\Models\RepricingRequest::query()
                ->where('status', \App\Models\RepricingRequest::STATUS_PENDING)
                ->where('submitted_by', $user->id)
                ->count();
        }

        $activeTab = $request->string('tab')->toString() === 'pengajuan' ? 'pengajuan' : 'pengiriman';

        return [
            'activeTab' => $activeTab,
            'canViewPengajuan' => $this->canViewPengajuanTab(),
            'canApprove' => $user?->can('approve repricing') ?? false,
            'canSubmit' => ($user?->can('submit repricing') || $user?->can('change price')) ?? false,
            'pengajuanBadge' => $pengajuanBadge,
        ];
    }

    private function canViewPengajuanTab(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('view pengajuan')
            || $user->can('submit repricing')
            || $user->can('approve repricing')
            || $user->can('change price')
        );
    }

    public function pricingData(Request $request): JsonResponse
    {
        /** @var EloquentCollection<int, Barang> $barangRows */
        $barangRows = $this->pricingQuery($request)
            ->with(['products', 'pendingRepricingRequest.submitter', 'invoiceItems.invoice.items', 'gudangChina'])
            ->latest()
            ->get();

        $markingLookup = $this->pricingMarkingLookup($barangRows);

        $barangs = $barangRows->map(function (Barang $barang) use ($markingLookup) {
                $pending = $barang->pendingRepricingRequest;
                $shipping = BarangShippingPricing::calculate($barang);
                $invoiceItem = $barang->invoiceItems->first();
                $invoice = $invoiceItem?->invoice;
                $invoiceShipmentCount = $invoice ? $invoice->distinctShipmentCount() : 0;
                $isConsolidatedInvoice = $invoice !== null && $invoice->isMultiShipment();
                $markingRecord = $barang->marking !== null && $barang->marking !== ''
                    ? $markingLookup->get($barang->marking)
                    : null;
                $gudangName = trim((string) ($barang->gudangChina?->name ?? $barang->warehouse_origin ?? ''));
                $branchCity = trim((string) ($markingRecord?->branch?->name
                    ?? $markingRecord?->branch?->code
                    ?? $barang->destination
                    ?? ''));
                $routeLabel = match (true) {
                    $gudangName !== '' && $branchCity !== '' => $gudangName.' - '.$branchCity,
                    $gudangName !== '' => $gudangName,
                    $branchCity !== '' => $branchCity,
                    default => null,
                };

                $row = [
                    'id' => $barang->id,
                    'no_index' => $barang->no_index,
                    'no_po' => $barang->no_po,
                    'container_code' => $barang->container_code,
                    'tanggal_masuk' => $barang->tanggal_masuk?->format('Y-m-d'),
                    'tanggal_masuk_display' => $barang->tanggal_masuk
                        ? $barang->tanggal_masuk->locale(app()->getLocale())->translatedFormat('d M Y')
                        : null,
                    'marketing_name' => $markingRecord?->sales?->name,
                    'route_label' => $routeLabel,
                    'partai_display' => $this->pricingPartaiLabel($barang),
                    'marking' => $barang->marking,
                    'destination' => $barang->destination,
                    'ctns' => $barang->ctns,
                    'total_cbm' => $barang->total_cbm,
                    'barang_summary' => $barang->itemsSummary(),
                    'price_per_cbm' => $barang->price_per_cbm,
                    'extra_charge' => $barang->extra_charge,
                    'weight_price' => $barang->weight_price,
                    'weight_threshold_kg' => $barang->weight_threshold_kg,
                    'total_gross_weight' => $barang->total_gross_weight,
                    'minimum_charge_cbm' => BarangShippingPricing::resolveMinimumChargeCbm($barang),
                    'shipping_method' => $shipping['method'],
                    'shipping_subtotal' => $shipping['subtotal_idr'],
                    'invoice_id' => $invoice?->id,
                    'invoice_no' => $invoice?->invoice_no,
                    'invoice_shipment_count' => $invoiceShipmentCount,
                    'invoice_consolidated_summary' => $isConsolidatedInvoice
                        ? __('finance.consolidated_summary', ['count' => $invoiceShipmentCount])
                        : null,
                    'pending_repricing' => $pending ? [
                        'id' => $pending->id,
                        'pricing_mode' => $pending->pricing_mode,
                        'new_price_per_cbm' => $pending->new_price_per_cbm,
                        'new_extra_charge' => $pending->new_extra_charge,
                        'new_weight_price' => $pending->new_weight_price,
                        'old_price_per_cbm' => $pending->old_price_per_cbm,
                        'old_extra_charge' => $pending->old_extra_charge,
                        'old_weight_price' => $pending->old_weight_price,
                        'reason' => $pending->reason,
                        'proof_url' => \App\Support\PublicStorageUrl::url($pending->proof_path),
                        'submitted_by' => $pending->submitter?->name,
                        'submitted_at' => $pending->submitted_at?->toIso8601String(),
                    ] : null,
                ];

                if (auth()->user()?->can('view harga modal')) {
                    $row['cost_price_per_cbm'] = $barang->cost_price_per_cbm;
                    $row['cost_weight_price'] = $barang->cost_weight_price;
                    $row['company_loyalty_amount'] = $barang->company_loyalty_amount;
                }

                return $row;
            });

        return response()->json($barangs);
    }

    public function updateCostPrice(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit harga modal'), 403);

        $data = $request->validate([
            'cost_price_per_cbm' => 'required|numeric|min:0',
            'cost_weight_price' => 'required|numeric|min:0',
        ]);

        $old = $barang->only(['cost_price_per_cbm', 'cost_weight_price']);

        $barang->update([
            'cost_price_per_cbm' => round((float) $data['cost_price_per_cbm'], 2),
            'cost_weight_price' => round((float) $data['cost_weight_price'], 2),
        ]);

        ShipmentEarningsSettlement::syncForBarang($barang);

        ActivityLog::record(
            'Cost price updated',
            $barang,
            $old,
            $barang->only(['cost_price_per_cbm', 'cost_weight_price']),
        );

        return response()->json([
            'success' => true,
            'message' => __('finance.cost_price_saved'),
        ]);
    }

    private function pricingQuery(Request $request): Builder
    {
        $query = Barang::with('customer', 'containers');

        $user = auth()->user();
        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictBarangsQuery($query, $user);
        }

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('no_index', 'like', "%{$search}%")
                ->orWhere('marking', 'like', "%{$search}%")
                ->orWhere('no_po', 'like', "%{$search}%")
                ->orWhereHas('containers', fn ($c) => $c->where('partai', 'like', "%{$search}%")));
        }

        if ($from = $request->get('start_load_date')) {
            $query->whereHas('containers', fn ($q) => $q->whereDate('load_date', '>=', $from));
        }
        if ($to = $request->get('end_load_date')) {
            $query->whereHas('containers', fn ($q) => $q->whereDate('load_date', '<=', $to));
        }

        if ($show = $request->get('show')) {
            if ($show === 'priced') {
                $query->where('price_per_cbm', '>', 0);
            } elseif ($show === 'unpriced') {
                $query->where('price_per_cbm', '<=', 0);
            }
        }

        return $query;
    }

    /** @return Collection<string, Marking> */
    private function pricingMarkingLookup(EloquentCollection $barangs): Collection
    {
        $codes = $barangs->pluck('marking')
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        $codeCol = Marking::codeColumn();
        if ($codeCol === null) {
            return collect();
        }

        return Marking::query()
            ->with([
                'sales:id,name',
                'branch:id,code,name',
            ])
            ->whereIn($codeCol, $codes)
            ->get()
            ->keyBy(static fn (Marking $m) => $m->displayCode());
    }

    private function pricingPartaiLabel(Barang $barang): ?string
    {
        $codes = $barang->containers
            ->pluck('partai')
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isNotEmpty()) {
            return $codes->implode(', ');
        }

        return null;
    }

    public function downloadInvoiceBlankTemplate(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        return (new InvoiceBlankTemplateExport())->download();
    }

    public function generateInvoice(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'barang_ids' => 'required|array|min:1',
            'barang_ids.*' => 'exists:barangs,id',
            'price_per_cbm' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
        ]);

        $invoices = DB::transaction(function () use ($data) {
            $barangs = Barang::query()
                ->with('containers')
                ->whereIn('id', $data['barang_ids'])
                ->where('user_id', $data['user_id'])
                ->get();

            $explicitDue = ! empty($data['due_date']) ? (string) $data['due_date'] : null;
            $created = [];

            foreach (InvoiceConsolidation::groupBarangsByScope($barangs) as $scopeBarangs) {
                $scopeBarangs = $scopeBarangs->values();
                $unbilled = $scopeBarangs->filter(
                    static fn (Barang $barang): bool => ! $barang->invoiceItems()->exists(),
                )->values();

                if ($unbilled->isEmpty()) {
                    continue;
                }

                $due = InvoiceDueResolver::resolveForBarangs($scopeBarangs, $explicitDue);
                $existing = InvoiceConsolidation::findOpenInvoiceForBarang($unbilled->first());

                if ($existing !== null && InvoiceConsolidation::isInvoiceOpenForMoreItems($existing)) {
                    $invoice = $existing;
                    foreach ($unbilled as $barang) {
                        if ($barang->invoiceItems()->exists()) {
                            continue;
                        }
                        InvoiceConsolidation::attachBarangToInvoice($invoice, $barang);
                    }
                    $invoice = $invoice->fresh(['items.barang']);
                    $invoice->update([
                        'price_per_cbm' => $data['price_per_cbm'],
                        'status' => InvoiceDueResolver::statusForResolved($due, 'sent'),
                        'due_date' => $due['due_date'],
                    ]);
                    $invoice->recalculate();
                    $created[] = $invoice;

                    continue;
                }

                $invoice = Invoice::create([
                    'invoice_no' => Invoice::generateNumber($unbilled),
                    'user_id' => $data['user_id'],
                    'price_per_cbm' => $data['price_per_cbm'],
                    'status' => InvoiceDueResolver::statusForResolved($due, 'sent'),
                    'due_date' => $due['due_date'],
                    'created_by' => auth()->id(),
                ]);

                foreach ($unbilled as $barang) {
                    $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'barang_id' => $barang->id,
                        'cbm' => $barang->total_cbm,
                        'price_per_cbm' => (float) $barang->price_per_cbm,
                        'insurance' => $insuranceAmount,
                        'extra' => (float) $barang->extra_charge,
                    ]);
                }

                $invoice->recalculate();
                $created[] = $invoice;
            }

            return collect($created);
        });

        if ($invoices->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Barang yang dipilih sudah memiliki invoice.',
            ], 422);
        }

        foreach ($invoices as $invoice) {
            ActivityLog::record('Invoice generated', $invoice);
        }

        $primary = $invoices->last();
        $invoiceNumbers = $invoices->pluck('invoice_no')->implode(', ');

        return response()->json([
            'success' => true,
            'message' => __('messages.invoice_created', ['invoice_no' => $invoiceNumbers]),
            'data' => $primary?->load('items'),
            'invoices' => $invoices->map(static fn (Invoice $invoice) => $invoice->load('items')),
        ]);
    }

    public function showInvoice(Invoice $invoice): JsonResponse
    {
        abort_unless(Marking::invoiceVisibleToUser($invoice), 403);

        InvoiceConsolidation::repairDraftConsolidationForInvoice($invoice);
        $invoice = $invoice->fresh();
        $invoice->load('items.barang', 'customer', 'payments.reviewer', 'promo', 'claims.reviewer', 'claims.submitter');
        $invoice->refreshTotalsFromBarangIfNeeded();
        $invoice->refresh()->load('items.barang', 'customer', 'payments.reviewer', 'promo', 'claims.reviewer', 'claims.submitter');
        $invoiceView = InvoicePdfData::forInvoice($invoice);
        $shipmentPayload = InvoicePdfData::apiShipmentPayload($invoice);

        $canExportInvoice = auth()->user()?->canAny(['view invoice', 'export invoice']);
        $consolidatedPdfUrl = $canExportInvoice ? route('export.invoice.pdf', $invoice) : null;

        $barangIdByIndex = [];
        foreach ($invoice->items as $item) {
            if ($item->barang === null) {
                continue;
            }
            $key = trim((string) ($item->barang->no_index ?? '')) ?: '-';
            $barangIdByIndex[$key] ??= (int) $item->barang_id;
        }

        $barangPdfUrl = static function (?int $barangId) use ($canExportInvoice): ?string {
            if (! $canExportInvoice || $barangId === null || $barangId <= 0) {
                return null;
            }

            return route('barang.invoice.pdf', $barangId);
        };

        $enrichRows = static function (array $rows) use ($barangIdByIndex, $barangPdfUrl): array {
            return array_map(static function (array $row) use ($barangIdByIndex, $barangPdfUrl): array {
                $key = trim((string) ($row['index_no'] ?? '')) ?: '-';
                $barangId = $barangIdByIndex[$key] ?? null;
                $row['barang_id'] = $barangId;
                $row['pdf_url'] = $barangPdfUrl($barangId);

                return $row;
            }, $rows);
        };

        $shipmentRows = $enrichRows($invoiceView['tableRows'] ?? []);
        $shipmentSections = array_map(static function (array $section) use ($enrichRows, $barangIdByIndex, $barangPdfUrl): array {
            $key = trim((string) ($section['no_index'] ?? '')) ?: '-';
            $barangId = $barangIdByIndex[$key] ?? null;
            $section['barang_id'] = $barangId;
            $section['pdf_url'] = $barangPdfUrl($barangId);
            $section['rows'] = $enrichRows($section['rows'] ?? []);

            return $section;
        }, $shipmentPayload['sections'] ?? []);

        $totalPaid = (float) $invoice->payments->where('status', 'approved')->sum('amount');
        $remaining = max(0, (float) $invoice->grand_total - $totalPaid);
        $pendingCount = (int) $invoice->payments->where('status', 'pending')->count();

        return response()->json([
            ...$invoice->toArray(),
            'consolidated_pdf_url' => $consolidatedPdfUrl,
            'shipment_rows' => $shipmentRows,
            'is_multi_shipment' => $shipmentPayload['is_multi_shipment'],
            'shipment_count' => $shipmentPayload['shipment_count'],
            'consolidated_summary' => $shipmentPayload['consolidated_summary'],
            'shipment_sections' => $shipmentSections,
            'before_discount_label' => $invoiceView['perhitunganLabel'],
            'total_before_claims' => $invoice->totalBeforeClaims(),
            'total_before_claims_label' => $invoiceView['totalBeforeClaimsLabel'] ?? InvoicePdfData::formatRp($invoice->totalBeforeClaims()),
            'total_claim_amount' => (float) ($invoice->claim_deduction_total ?? 0),
            'marking_label' => $invoice->resolvedMarkingLabel(),
            'display_status' => InvoiceStatusPresenter::forInvoice(
                $invoice,
                $totalPaid,
                $remaining,
                $pendingCount,
            ),
            'due_date_formatted' => InvoiceStatusPresenter::formatDueDate($invoice->due_date),
            'due_date_urgent' => InvoiceStatusPresenter::dueDateCellClass($invoice->due_date) !== '',
            'remaining_class' => InvoiceStatusPresenter::remainingAmountClass($invoice->due_date, $remaining),
            'claims' => $invoice->claims->map(static fn ($claim) => [
                'id' => $claim->id,
                'claim_type' => $claim->claim_type,
                'claim_type_label' => \App\Models\Claim::typeLabel($claim->claim_type),
                'amount' => (float) $claim->amount,
                'status' => $claim->status,
                'status_label' => \App\Models\Claim::statusLabel($claim->status),
                'notes' => $claim->notes,
                'proof_url' => $claim->proof_url,
                'review_notes' => $claim->review_notes,
                'submitter_name' => $claim->submitter?->name,
                'reviewer_name' => $claim->reviewer?->name,
                'created_at' => $claim->created_at?->format('d M Y H:i'),
            ])->values(),
        ]);
    }

    public function promoOptions(): JsonResponse
    {
        $promos = MasterPromo::active()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'persen', 'amount_rp']);

        return response()->json($promos);
    }

    public function applyPromo(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless(auth()->user()?->can('apply invoice promo'), 403);
        abort_unless(Marking::invoiceVisibleToUser($invoice), 403);

        $data = $request->validate([
            'promo_id' => 'nullable|exists:master_promos,id',
        ]);

        $old = [
            'promo_name' => $invoice->promo_name,
            'promo_type' => $invoice->promo_type,
            'promo_persen' => $invoice->promo_persen,
            'promo_amount_rp' => $invoice->promo_amount_rp,
            'promo_discount' => $invoice->promo_discount,
            'grand_total' => $invoice->grand_total,
        ];

        if (empty($data['promo_id'])) {
            $invoice->promo_id = null;
            $invoice->promo_name = null;
            $invoice->promo_type = null;
            $invoice->promo_persen = null;
            $invoice->promo_amount_rp = null;
        } else {
            $promo = MasterPromo::active()->findOrFail($data['promo_id']);
            $snapshot = $promo->snapshotForInvoice();
            $invoice->promo_id = $promo->id;
            $invoice->promo_name = $promo->name;
            $invoice->promo_type = $snapshot['type'];
            $invoice->promo_persen = $snapshot['persen'];
            $invoice->promo_amount_rp = $snapshot['amount_rp'];
        }

        $invoice->recalculate();

        ActivityLog::record(
            empty($data['promo_id']) ? 'Invoice promo removed' : 'Invoice promo applied',
            $invoice,
            $old,
            [
                'promo_name' => $invoice->promo_name,
                'promo_type' => $invoice->promo_type,
                'promo_persen' => $invoice->promo_persen,
                'promo_amount_rp' => $invoice->promo_amount_rp,
                'promo_discount' => $invoice->promo_discount,
                'grand_total' => $invoice->grand_total,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => empty($data['promo_id'])
                ? __('finance.promo_removed')
                : __('finance.promo_applied', ['name' => $invoice->promo_name]),
            'data' => $invoice->fresh()->load('promo'),
        ]);
    }

    public function customerBarangs(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $barangs = Barang::where('user_id', $request->user_id)
            ->whereDoesntHave('invoiceItems')
            ->select('id', 'no_index', 'marking', 'total_cbm', 'ctns', 'price_per_cbm', 'extra_charge', 'status')
            ->get();

        return response()->json($barangs);
    }
}
