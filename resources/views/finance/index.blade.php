@extends('layouts.app')
@section('title', __('finance.title'))

@section('topbar-actions')
@role('super_admin')
<a href="{{ route('finance.invoice.blank-template') }}" class="btn btn-accent btn-sm" title="{{ __('finance.generate_invoice_template_hint') }}">
    <i class="bi bi-file-earmark-excel me-1"></i>{{ __('finance.generate_invoice') }}
</a>
<button type="button" class="btn btn-glass btn-sm" id="btnBackfillMasukInvoice" title="Buat invoice draft untuk barang masuk yang belum punya tagihan (tanpa terminal)">
    <i class="bi bi-arrow-repeat me-1"></i>Sync Invoice Masuk
</button>
@endrole
@endsection

@push('styles')
<style>
    .finance-invoice-table td.invoice-amount-paid,
    .finance-invoice-table td.invoice-amount-remaining {
        font-weight: 600;
    }
    .finance-invoice-table td.invoice-amount-paid { color: #16a34a !important; }
    .finance-invoice-table td.invoice-amount-remaining { color: #dc2626 !important; }
    .stat-card-link[data-finance-card] { cursor: pointer; }
    .finance-table-loading {
        position: absolute;
        inset: 0;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.35);
        border-radius: inherit;
    }
    [data-theme="light"] .finance-table-loading {
        background: rgba(255, 255, 255, 0.55);
    }
</style>
@endpush

@section('content')
<input type="hidden" id="financeFilterCard" value="{{ $activeCard ?? '' }}">
{{-- Stats --}}
<div class="row g-3 mb-4" id="financeStatsRow">
    <div class="col-6 col-md-3">
        <button type="button" class="glass stat-card stat-card-link w-100 border-0 text-start {{ empty($activeCard) ? 'stat-card-active' : '' }}" data-finance-card="">
            <div class="stat-icon"><i class="bi bi-receipt"></i></div>
            <div class="stat-label">{{ __('finance.stat_total_invoice') }}</div>
            <div class="stat-value" id="financeStatTotal">{{ $stats['total'] }}</div>
        </button>
    </div>
    <div class="col-6 col-md-3">
        <button type="button" class="glass stat-card stat-card-link w-100 border-0 text-start {{ $activeCard === 'unpaid' ? 'stat-card-active' : '' }}" data-finance-card="unpaid">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-label">{{ __('finance.stat_unpaid') }}</div>
            <div class="stat-value text-warning" id="financeStatUnpaid">{{ $stats['unpaid'] }}</div>
        </button>
    </div>
    <div class="col-6 col-md-3">
        <button type="button" class="glass stat-card stat-card-link w-100 border-0 text-start {{ $activeCard === 'overdue' ? 'stat-card-active' : '' }}" data-finance-card="overdue">
            <div class="stat-icon"><i class="bi bi-calendar-x"></i></div>
            <div class="stat-label">{{ __('finance.stat_overdue') }}</div>
            <div class="stat-value" style="color:#dc2626" id="financeStatOverdue">{{ $stats['overdue'] }}</div>
        </button>
    </div>
    <div class="col-6 col-md-3">
        <button type="button" class="glass stat-card stat-card-link w-100 border-0 text-start {{ $activeCard === 'paid' ? 'stat-card-active' : '' }}" data-finance-card="paid">
            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-label">{{ __('finance.stat_paid') }}</div>
            <div class="stat-value" style="color:#22c55e" id="financeStatPaid">{{ $stats['paid'] }}</div>
        </button>
    </div>
</div>

{{-- Filter --}}
@php
    $financeSearchCol = empty($isSalesScoped) ? 'col-md-2' : 'col-md-2';
@endphp
<div class="glass p-3 mb-4">
    <form id="financeFilterForm" class="row g-2 align-items-end" onsubmit="return false;">
        <div class="{{ $financeSearchCol }}">
            <label class="form-label">{{ __('common.search') }}</label>
            <input type="text" id="financeFilterSearch" name="search" class="form-control" placeholder="{{ __('finance.search_placeholder') }}" value="{{ request('search') }}" autocomplete="off">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('common.status') }}</label>
            <select id="financeFilterStatus" name="status" class="form-select select2" data-placeholder="{{ __('common.all_status') }}">
                <option value="">{{ __('common.all') }}</option>
                @foreach(['draft','sent','paid','verified'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        @unless($isSalesScoped ?? false)
        <div class="col-md-2">
            <label class="form-label">{{ __('finance.filter_sales') }}</label>
            <select id="financeFilterSales" name="sales_id" class="form-select select2" data-placeholder="{{ __('finance.all_sales') }}">
                <option value="">{{ __('finance.all_sales') }}</option>
                @foreach($salesUsers as $s)
                <option value="{{ $s->id }}" @selected((string) request('sales_id') === (string) $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <div class="col-md-2">
            <label class="form-label" for="financeFilterPartai">{{ __('barang.filter_partai') }}</label>
            <select id="financeFilterPartai" name="partai" class="form-select select2" data-placeholder="{{ __('barang.filter_all_partai') }}">
                <option value="">{{ __('barang.filter_all_partai') }}</option>
                <option value="belum_di_muat" @selected(request('partai') === 'belum_di_muat')>{{ __('barang.partai_not_loaded') }}</option>
                @foreach(($partaiOptions ?? []) as $partai)
                <option value="{{ $partai }}" @selected(request('partai') === $partai)>{{ $partai }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="financeFilterPaidDatePreset">{{ __('finance.paid_date_filter') }}</label>
            <select id="financeFilterPaidDatePreset" name="paid_date_preset" class="form-select">
                <option value="">{{ __('common.date_preset_none') }}</option>
                <option value="today" @selected(request('paid_date_preset')==='today')>{{ __('common.date_preset_today') }}</option>
                <option value="yesterday" @selected(request('paid_date_preset')==='yesterday')>{{ __('common.date_preset_yesterday') }}</option>
                <option value="7d" @selected(request('paid_date_preset')==='7d')>{{ __('common.date_preset_7d') }}</option>
                <option value="30d" @selected(request('paid_date_preset')==='30d')>{{ __('common.date_preset_30d') }}</option>
                <option value="custom" @selected(request('paid_date_preset')==='custom')>{{ __('common.date_preset_custom') }}</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-accent w-100" id="btnExportFinanceInvoices" style="height:42px">
                <i class="bi bi-file-earmark-excel me-1"></i>{{ __('finance.export_excel') }}
            </button>
        </div>
        <div id="financeFilterPaidDateCustomRow" class="col-12 row g-2 {{ request('paid_date_preset') === 'custom' ? '' : 'd-none' }}">
            <div class="col-md-3 col-lg-2">
                <label class="form-label mb-1 small" for="financeFilterPaidDateFrom">{{ __('barang.filter_tanggal_from') }}</label>
                <input type="date" id="financeFilterPaidDateFrom" name="paid_date_from" class="form-control" value="{{ request('paid_date_from') }}" autocomplete="off">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label mb-1 small" for="financeFilterPaidDateTo">{{ __('barang.filter_tanggal_to') }}</label>
                <input type="date" id="financeFilterPaidDateTo" name="paid_date_to" class="form-control" value="{{ request('paid_date_to') }}" autocomplete="off">
            </div>
        </div>
    </form>
</div>

{{-- Invoice Table --}}
<div class="glass p-4 position-relative" id="financeTableWrap">
    <div id="financeTableLoading" class="finance-table-loading d-none" aria-hidden="true">
        <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
    </div>
    <div class="table-responsive">
        <table class="table-glass finance-invoice-table">
            <thead>
                <tr>
                    <th>{{ __('finance.col_marking') }}</th>
                    <th>{{ __('finance.col_marketing') }}</th>
                    <th>{{ __('finance.col_partai') }}</th>
                    <th>{{ __('finance.col_grand_total') }}</th>
                    <th>{{ __('finance.col_total_claim') }}</th>
                    <th>{{ __('finance.col_paid') }}</th>
                    <th>{{ __('finance.col_remaining') }}</th>
                    <th>{{ __('finance.col_due_date') }}</th>
                    <th>{{ __('finance.col_paid_date') }}</th>
                    <th class="finance-col-status">{{ __('common.status') }}</th>
                    <th>{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody id="financeInvoiceTbody">
                @forelse($invoices as $inv)
                @php
                    $totalPaid = $inv->payments->where('status', 'approved')->sum('amount');
                    $remaining = max(0, $inv->grand_total - $totalPaid);
                    $pendingCount = $inv->payments->where('status', 'pending')->count();
                    $invStatus = \App\Support\InvoiceStatusPresenter::forInvoice($inv, $totalPaid, $remaining, $pendingCount);
                @endphp
                <tr>
                    <td>{{ $inv->resolvedMarkingLabel() ?: '-' }}</td>
                    <td>{{ $inv->resolvedMarketingLabel($markingLookup ?? null) }}</td>
                    <td>{{ $inv->resolvedPartaiLabel() }}</td>
                    <td class="fw-700" style="color:var(--text-primary)">Rp {{ number_format($inv->grand_total, 0, ',', '.') }}</td>
                    <td class="fw-600" style="color:var(--text-primary)">Rp {{ number_format((float) ($inv->claim_deduction_total ?? 0), 0, ',', '.') }}</td>
                    <td class="invoice-amount-paid fw-600" style="color:#16a34a !important">Rp {{ number_format($totalPaid, 0, ',', '.') }}</td>
                    <td class="invoice-amount-remaining fw-600" style="color:#dc2626 !important">Rp {{ number_format($remaining, 0, ',', '.') }}</td>
                    <td class="fw-600" style="color:var(--text-primary)">{{ \App\Support\InvoiceStatusPresenter::formatDueDate($inv->due_date) }}</td>
                    <td class="fw-600" style="color:var(--text-primary)">{{ \App\Support\InvoiceStatusPresenter::formatDueDate($inv->resolvedPaidAt()) }}</td>
                    <td class="finance-col-status"><span class="finance-status-badge {{ $invStatus['class'] }}">{{ $invStatus['label'] }}</span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-accent btn-sm btn-detail-invoice" data-id="{{ $inv->id }}" title="Detail & Pembayaran">
                                <i class="bi bi-eye"></i>
                            </button>
                            @canany(['view invoice', 'export invoice'])
                            <a href="{{ route('export.invoice.pdf', $inv) }}" class="btn btn-glass btn-sm" title="PDF"><i class="bi bi-cash-stack" style="color:#16a34a"></i></a>
                            @endcanany
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="11" class="text-center py-4" style="color:var(--text-muted)">{{ __('finance.empty_invoices') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Detail Invoice + Payments Modal --}}
<div class="modal fade" id="detailModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable finance-invoice-detail-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-receipt-cutoff me-2 text-accent"></i>{{ __('finance.detail_payment_title') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <div class="text-center py-4" style="color:var(--text-muted)">{{ __('tracking.loading') }}</div>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left me-1"></i>{{ __('common.back') }}
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Tambah Pembayaran (stacked on detail) --}}
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-upload me-2 text-accent"></i>{{ __('finance.add_payment_modal') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.close') }}"></button>
            </div>
            <div class="modal-body" id="addPaymentBody"></div>
        </div>
    </div>
</div>

{{-- Approve/Reject Modal --}}
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="reviewTitle">{{ __('finance.review_payment') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="reviewProofArea" class="text-center mb-3"></div>
                <div id="reviewInfo" class="mb-3"></div>
                <form id="rejectForm" class="d-none">
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.reject_reason') }}</label>
                        <textarea name="review_notes" class="form-control" rows="2" required placeholder="{{ __('finance.reject_reason_placeholder') }}"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal" id="btnReviewCancel">
                    <i class="bi bi-x-lg me-1"></i>{{ __('common.cancel') }}
                </button>
                <div class="d-flex gap-2 flex-grow-1 justify-content-end" id="reviewActions"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $financeI18n = \App\Support\JsTranslations::merge(['finance', 'common', 'swal', 'barang']);
@endphp
<script>
    Object.assign(window.__t || {}, @json($financeI18n));
    function ft(key, fallback) { return (window.__t && window.__t[key]) || fallback || key; }
    function financeFmt(n) { return Number(n || 0).toLocaleString('id'); }
    window.esc = window.esc || function(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    };
    function invoiceItemTableHeaders() {
        return '<th>' + ft('col_no_index', 'No Urut') + '</th>'
            + '<th>' + ft('col_item', 'Item') + '</th>'
            + '<th>' + ft('col_volume', 'Volume') + '</th>'
            + '<th>' + ft('col_weight', 'Berat') + '</th>'
            + '<th>' + ft('col_base_price', 'Harga Dasar') + '</th>'
            + '<th>' + ft('col_line_total', 'Total') + '</th>';
    }

    function renderConsolidatedPdfButton(url) {
        if (!url || !window._canExportInvoice) {
            return '';
        }
        var label = ft('download_invoice_gabungan', 'Unduh PDF Gabungan');
        return '<a href="' + esc(url) + '" class="btn btn-glass btn-sm flex-shrink-0" target="_blank" rel="noopener"'
            + ' title="' + esc(label) + '">'
            + '<i class="bi bi-file-earmark-pdf me-1" style="color:#16a34a"></i>'
            + '<span class="small">' + esc(label) + '</span></a>';
    }

    function renderShipmentLineRows(rows) {
        let body = '';
        (rows || []).forEach(function (row) {
            const indexKey = row.index_no != null && row.index_no !== '' ? String(row.index_no) : '-';
            body += '<tr>'
                + '<td class="fw-600">' + esc(indexKey) + '</td>'
                + '<td class="fw-600">' + esc(row.description || '-') + '</td>'
                + '<td>' + esc(row.volume || '-') + '</td>'
                + '<td>' + esc(row.weight || '-') + '</td>'
                + '<td>' + esc(row.base_price || '-') + '</td>'
                + '<td class="fw-600" style="color:var(--text-primary)">' + esc(row.amount || '-') + '</td>'
                + '</tr>';
        });
        return body;
    }

    function renderInvoiceDetailItems(inv) {
        const shipmentRows = Array.isArray(inv.shipment_rows) ? inv.shipment_rows : [];
        const shipmentSections = Array.isArray(inv.shipment_sections) ? inv.shipment_sections : [];
        let body = '';

        if (shipmentRows.length) {
            body = renderShipmentLineRows(shipmentRows);
        } else if (shipmentSections.length) {
            shipmentSections.forEach(function (section) {
                body += renderShipmentLineRows(section.rows || []);
            });
        } else {
            (inv.items || []).forEach(function (it) {
                const indexKey = it.barang?.no_index || '-';
                body += '<tr>'
                    + '<td class="fw-600">' + esc(indexKey) + '</td>'
                    + '<td class="fw-600">' + esc(it.barang?.marking || '-') + '</td>'
                    + '<td>' + esc(it.cbm != null ? it.cbm : '-') + '</td>'
                    + '<td>' + esc(it.barang?.total_gross_weight != null ? (it.barang.total_gross_weight + ' kg') : '-') + '</td>'
                    + '<td>' + esc(it.price_per_cbm != null ? ('Rp ' + financeFmt(it.price_per_cbm) + ' / CBM') : '-') + '</td>'
                    + '<td class="fw-600" style="color:var(--text-primary)">Rp ' + financeFmt(it.line_total) + '</td>'
                    + '</tr>';
            });
        }

        if (!body) {
            return '';
        }

        return '<div class="table-responsive mb-4"><table class="table-glass"><thead><tr>'
            + invoiceItemTableHeaders()
            + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function renderFinanceInvoiceNo(invoiceNo) {
        const no = invoiceNo == null ? '' : String(invoiceNo);
        if (!no) {
            return '<span class="modal-stat-value text-muted">—</span>';
        }
        return '<span class="finance-invoice-no modal-stat-value" title="' + esc(no) + '">' + esc(no) + '</span>';
    }

    function formatInvoiceDueDate(inv) {
        if (inv.due_date_formatted && inv.due_date_formatted.indexOf('-') === -1) {
            return inv.due_date_formatted;
        }
        const raw = inv.due_date;
        if (!raw) return '—';
        const d = new Date(String(raw).includes('T') ? raw : raw + 'T00:00:00');
        if (isNaN(d.getTime())) return inv.due_date_formatted || '—';
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }
    var _canApprovePayment = @json(auth()->user()->can('approve payment'));
    var _canRejectPayment = @json(auth()->user()->can('reject payment'));
    var _canApplyPromo = @json(auth()->user()->can('apply invoice promo'));
    window._financeInvoiceDataUrl = @json(route('finance.index.data'));
    window._financeInvoiceExportUrl = @json(route('finance.index.export'));
    window._financeExportPdfBase = @json(url('/export/invoice'));
    window._canExportInvoice = @json(auth()->user()->canAny(['view invoice', 'export invoice']));
</script>
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Finance/Invoice.js') }}?v={{ time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrf = '{{ csrf_token() }}';
    const storageMediaUrl = @json(route('storage-media.show'));

    function paymentProofUrl(payment) {
        if (!payment) return null;

        const rawPath = String(payment.proof_file || '').trim();
        if (rawPath && !/^https?:\/\//i.test(rawPath)) {
            const normalized = rawPath.replace(/^\/+/, '').replace(/^public\//, '').replace(/^storage\//, '');
            return storageMediaUrl + '?path=' + encodeURIComponent(normalized);
        }

        const legacy = String(payment.proof_url || '').trim();
        if (!legacy) return null;
        if (/^https?:\/\//i.test(legacy) && legacy.includes('/public/storage/')) {
            const match = legacy.match(/(?:storage\/)(payment-proofs\/[^?#]+)/i);
            if (match) {
                return storageMediaUrl + '?path=' + encodeURIComponent(match[1]);
            }
        }
        if (/^https?:\/\//i.test(legacy)) return legacy;

        return null;
    }

    const esc = window.esc;

    function renderPaymentProofHtml(payment) {
        const url = paymentProofUrl(payment);
        if (!url) return '<p class="small text-muted mb-0">' + ft('no_proof', 'Tidak ada bukti transfer.') + '</p>';
        const path = String(payment.proof_file || payment.proof_url || '');
        const ext = path.split('.').pop().toLowerCase().split('?')[0];
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            return '<a href="' + url + '" target="_blank" rel="noopener" class="d-inline-block">'
                + '<img src="' + url + '" class="img-fluid rounded border" style="max-height:300px" alt="Bukti transfer"'
                + ' onerror="this.closest(\'a\').outerHTML=\'<p class=\\\'small text-danger\\\'>' + ft('proof_load_failed', 'Gagal memuat gambar bukti.') + '</p>\'"></a>';
        }
        return '<a href="' + url + '" target="_blank" rel="noopener" class="btn btn-glass">'
            + '<i class="bi bi-file-pdf me-1"></i>' + ft('view_proof_pdf', 'Lihat Bukti PDF') + '</a>';
    }

    function financeModal(el) {
        if (window.getBootstrapModal) {
            return window.getBootstrapModal(el);
        }
        const node = typeof el === 'string' ? document.querySelector(el) : el;
        return node ? bootstrap.Modal.getOrCreateInstance(node, { backdrop: 'static', keyboard: false }) : null;
    }

    function financeModalCleanup() {
        if (window.syncBootstrapModalState) {
            window.syncBootstrapModalState();
        }
    }

    ['#detailModal', '#addPaymentModal', '#reviewModal'].forEach(function (sel) {
        financeModal(sel);
    });

    const detailModalEl = document.getElementById('detailModal');
    if (detailModalEl) {
        detailModalEl.addEventListener('hide.bs.modal', function () {
            ['#reviewModal', '#addPaymentModal'].forEach(function (sel) {
                const child = document.querySelector(sel);
                if (child && child.classList.contains('show')) {
                    financeModal(child)?.hide();
                }
            });
        });
    }

    function showStackedModal(childSelector, parentSelector) {
        const childEl = document.querySelector(childSelector);
        const parentEl = parentSelector ? document.querySelector(parentSelector) : null;
        if (!childEl) return;

        if (parentEl && !parentEl.classList.contains('show')) {
            financeModal(parentEl)?.show();
        }

        const applyStackLayers = function () {
            if (parentEl && parentEl.classList.contains('show')) {
                parentEl.classList.add('modal-under-stack');
            }
            childEl.classList.add('modal-stack-top');
            document.querySelectorAll('.modal-backdrop').forEach(function (el) {
                el.classList.remove('modal-stack-backdrop');
            });
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length) {
                backdrops[backdrops.length - 1].classList.add('modal-stack-backdrop');
            }
            financeModalCleanup();
        };

        const onHidden = function () {
            childEl.classList.remove('modal-stack-top');
            if (parentEl) {
                parentEl.classList.remove('modal-under-stack');
            }
            document.querySelectorAll('.modal-backdrop.modal-stack-backdrop').forEach(function (el) {
                el.classList.remove('modal-stack-backdrop');
            });
            childEl.removeEventListener('hidden.bs.modal', onHidden);
            financeModalCleanup();
        };

        childEl.addEventListener('shown.bs.modal', applyStackLayers, { once: true });
        childEl.addEventListener('hidden.bs.modal', onHidden);
        financeModal(childEl)?.show();
    }

    function financeReloadDetailModal() {
        const invoiceId = window._currentInvoiceId || window._lastPayment?.invoice_id;
        if (!invoiceId) return;
        const $trigger = $('.btn-detail-invoice[data-id="' + invoiceId + '"]');
        if ($trigger.length) {
            $trigger.trigger('click');
        }
    }

    // ── Detail Invoice + Payments ──
    $(document).on('click', '.btn-detail-invoice', function() {
        const id = $(this).data('id');
        window._currentInvoiceId = id;
        $('#detailContent').html('<div class="text-center py-4" style="color:var(--text-muted)"><div class="spinner-border spinner-border-sm"></div> Memuat...</div>');
        financeModal('#detailModal')?.show();

        $.get(`/finance/invoice/${id}`, function(inv) {
            const paid = (inv.payments||[]).filter(p=>p.status==='approved').reduce((s,p)=>s+parseFloat(p.amount),0);
            const remaining = Math.max(0, parseFloat(inv.grand_total) - paid);
            const beforeDiscount = parseFloat(inv.subtotal||0) + parseFloat(inv.insurance_total||0) + parseFloat(inv.extra_charges||0);
            const promoDiscount = parseFloat(inv.promo_discount||0);
            const beforeDiscountLabel = inv.before_discount_label || ('Rp ' + fmt(beforeDiscount));
            const totalClaim = parseFloat(inv.total_claim_amount ?? inv.claim_deduction_total ?? 0);
            const totalBeforeClaims = parseFloat(inv.total_before_claims ?? (parseFloat(inv.grand_total || 0) + totalClaim));
            const totalBeforeClaimsLabel = inv.total_before_claims_label || ('Rp ' + fmt(totalBeforeClaims));
            const ds = inv.display_status || {};
            const dueLabel = formatInvoiceDueDate(inv);
            const markingLabel = inv.marking_label ? esc(inv.marking_label) : '—';
            let html = `
            <div class="row g-3 mb-2 modal-stat-grid finance-detail-summary-grid">
                <div class="col-12 col-sm-6 col-xl-3"><div class="modal-stat"><div class="modal-stat-label">${ft('col_invoice_no', 'Invoice')}</div>${renderFinanceInvoiceNo(inv.invoice_no)}</div></div>
                <div class="col-12 col-sm-6 col-xl-3"><div class="modal-stat"><div class="modal-stat-label">${ft('col_marking', 'Marking')}</div><div class="modal-stat-value">${markingLabel}</div></div></div>
                <div class="col-12 col-sm-6 col-xl-3"><div class="modal-stat"><div class="modal-stat-label">${ft('col_due_date', 'Jatuh Tempo')}</div><div class="modal-stat-value">${esc(dueLabel)}</div></div></div>
                <div class="col-12 col-sm-6 col-xl-3"><div class="modal-stat"><div class="modal-stat-label">${ft('status', 'Status')}</div><div class="modal-stat-value"><span class="${ds.class||'badge-glass'}">${esc(ds.label||inv.status)}</span></div></div></div>
            </div>
            <div class="row g-3 mb-0 align-items-end modal-stat-grid finance-detail-summary-grid">
                <div class="col-12 col-lg-4">
                    <div class="modal-stat">
                        <div class="modal-stat-label">${ft('total_before_claims', 'Total sebelum potong klaim')}</div>
                        <div class="modal-stat-value fw-800">${esc(totalBeforeClaimsLabel)}</div>
                    </div>
                </div>
                <div class="col-6 col-lg-2"><div class="modal-stat"><div class="modal-stat-label">${ft('col_total_claim', 'Total Klaim')}</div><div class="modal-stat-value fw-800 invoice-amount-remaining">Rp ${fmt(totalClaim)}</div></div></div>
                <div class="col-6 col-lg-2"><div class="modal-stat"><div class="modal-stat-label">${ft('col_grand_total', 'Grand Total')}</div><div class="modal-stat-value fw-800">Rp ${fmt(inv.grand_total)}</div></div></div>
                <div class="col-12 col-lg-2"><div class="modal-stat"><div class="modal-stat-label">${ft('col_remaining', 'Sisa')}</div><div class="modal-stat-value fw-800 invoice-amount-remaining">Rp ${fmt(remaining)}</div></div></div>
            </div>
            <hr class="modal-section-hr">
            `;

            if (promoDiscount > 0) {
                html += `<div class="modal-section mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div><span class="small" style="color:var(--text-muted)">${ft('promo_label', 'Promo')}</span>
                    <span class="badge-glass ms-2">${inv.promo_name||'-'} (${formatPromoValue(inv)})</span></div>
                    <div><span class="small" style="color:var(--text-muted)">${ft('promo_discount', 'Diskon')}</span>
                    <span class="fw-700 text-warning ms-2">- Rp ${fmt(promoDiscount)}</span></div>
                </div>`;
            }

            if (_canApplyPromo) {
                html += `<div class="modal-section mb-4">
                    <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-percent me-1"></i>${ft('promo_section', 'Promo / Diskon')}</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small">${ft('promo_select', 'Pilih Promo')}</label>
                            <select id="invoicePromoSelect" class="form-select" data-invoice-id="${inv.id}">
                                <option value="">${ft('promo_none', 'Tanpa promo')}</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="button" class="btn btn-accent flex-fill" id="btnApplyPromo" data-invoice-id="${inv.id}">
                                <i class="bi bi-check2 me-1"></i>${ft('promo_apply', 'Terapkan')}
                            </button>
                            ${inv.promo_id ? `<button type="button" class="btn btn-glass flex-fill" id="btnRemovePromo" data-invoice-id="${inv.id}">
                                <i class="bi bi-x-lg me-1"></i>${ft('promo_remove', 'Hapus')}
                            </button>` : ''}
                        </div>
                    </div>
                    ${beforeDiscountLabel ? `<div class="small mt-2" style="color:var(--text-muted)">${ft('subtotal_before_discount', 'Total sebelum diskon')}: ${beforeDiscountLabel}</div>` : ''}
                </div>`;
            }

            const consolidatedPdfUrl = inv.consolidated_pdf_url
                || (window._financeExportPdfBase ? window._financeExportPdfBase + '/' + inv.id + '/pdf' : null);

            // Items table + consolidated PDF only
            html += `<div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-box-seam me-1"></i>${ft('item_barang', 'Item Barang')}</h6>
                ${renderConsolidatedPdfButton(consolidatedPdfUrl)}
            </div>`;
            html += renderInvoiceDetailItems(inv);
            html += '<div class="mb-4"></div>';

            // Claims summary section (so deduction source is clear)
            const approvedClaims = (inv.claims || []).filter(c => c.status === 'approved');
            if (approvedClaims.length) {
                let claimRows = '';
                approvedClaims.forEach(function (c, idx) {
                    claimRows += `<tr>
                        <td>${idx + 1}</td>
                        <td>${esc(c.claim_type_label || c.claim_type || '-')}</td>
                        <td>${esc(c.notes || '-')}</td>
                        <td class="fw-600" style="color:#dc2626">- Rp ${fmt(c.amount || 0)}</td>
                    </tr>`;
                });
                const totalClaim = approvedClaims.reduce((sum, c) => sum + Number(c.amount || 0), 0);
                html += `<h6 class="fw-700 mb-2" style="color:var(--text-primary)"><i class="bi bi-exclamation-diamond me-1"></i>Daftar Potongan Claim</h6>
                <div class="table-responsive mb-4"><table class="table-glass">
                    <thead><tr><th style="width:70px">No</th><th style="width:180px">Jenis Claim</th><th>Catatan</th><th style="width:170px">Nominal</th></tr></thead>
                    <tbody>${claimRows}</tbody>
                    <tfoot><tr><td colspan="3" class="text-end fw-600">Total Potongan Claim</td><td class="fw-700" style="color:#dc2626">- Rp ${fmt(totalClaim)}</td></tr></tfoot>
                </table></div>`;
            }

            // Payments section
            html += `<div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-credit-card me-1"></i>Riwayat Pembayaran</h6>
                ${remaining > 0 ? `<button class="btn btn-accent btn-sm" onclick="openAddPayment(${inv.id}, ${remaining})"><i class="bi bi-plus-lg me-1"></i>Tambah Bayar</button>` : ''}
            </div>`;

            if (!(inv.payments||[]).length) {
                html += `<div class="modal-stat" style="color:var(--text-muted)">Belum ada pembayaran.</div>`;
            } else {
                html += `<div class="table-responsive"><table class="table-glass"><thead><tr><th>Tgl</th><th>Pengirim</th><th>Jumlah</th><th>Bukti</th><th>Status</th><th>Aksi</th></tr></thead><tbody>`;
                (inv.payments||[]).forEach(p => {
                    const statusBadge = p.status==='approved' ? 'bg-success bg-opacity-10 text-success' : p.status==='pending' ? 'bg-warning bg-opacity-10 text-warning' : 'bg-danger bg-opacity-10 text-danger';
                    const proofUrl = paymentProofUrl(p);
                    const proofBtn = proofUrl ? `<a href="${proofUrl}" target="_blank" rel="noopener" class="btn btn-glass btn-sm"><i class="bi bi-image"></i></a>` : '';
                    let actionBtn = `<span class="small" style="color:var(--text-muted)">${p.reviewer?.name||'—'}</span>`;
                    if (p.status==='pending' && (_canApprovePayment || _canRejectPayment)) {
                        const paymentJson = encodeURIComponent(JSON.stringify(p));
                        actionBtn = `<button class="btn btn-accent btn-sm btn-review-payment" data-payment="${paymentJson}"><i class="bi bi-check2-square"></i> Review</button>`;
                    }

                    html += `<tr>
                        <td class="small">${new Date(p.created_at).toLocaleDateString('id')}</td>
                        <td>${p.sender_name||'-'}</td>
                        <td class="fw-600" style="color:#22c55e">Rp ${fmt(p.amount)}</td>
                        <td>${proofBtn}</td>
                        <td><span class="badge ${statusBadge}">${p.status}</span></td>
                        <td>${actionBtn}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            }

            $('#detailContent').html(html);

            if (_canApplyPromo) {
                $.getJSON('{{ route("finance.promos") }}', function(promos) {
                    const $sel = $('#invoicePromoSelect');
                    if (!$sel.length) return;
                    promos.forEach(p => {
                        $sel.append(`<option value="${p.id}" ${inv.promo_id == p.id ? 'selected' : ''}>${p.name} (${formatPromoOptionValue(p)})</option>`);
                    });
                });

                $('#btnApplyPromo').on('click', function() {
                    const invoiceId = $(this).data('invoice-id');
                    const promoId = $('#invoicePromoSelect').val();
                    $.ajax({
                        url: `/finance/invoice/${invoiceId}/promo`, method: 'POST',
                        headers: {'X-CSRF-TOKEN': csrf}, contentType: 'application/json',
                        data: JSON.stringify({ promo_id: promoId || null }),
                        success(r) {
                            showToast(r.message);
                            $('.btn-detail-invoice[data-id="'+invoiceId+'"]').trigger('click');
                        },
                        error(x) { showToast(x.responseJSON?.message||'Gagal','error'); }
                    });
                });

                $('#btnRemovePromo').on('click', function() {
                    const invoiceId = $(this).data('invoice-id');
                    $.ajax({
                        url: `/finance/invoice/${invoiceId}/promo`, method: 'POST',
                        headers: {'X-CSRF-TOKEN': csrf}, contentType: 'application/json',
                        data: JSON.stringify({ promo_id: null }),
                        success(r) {
                            showToast(r.message);
                            $('.btn-detail-invoice[data-id="'+invoiceId+'"]').trigger('click');
                        },
                        error(x) { showToast(x.responseJSON?.message||'Gagal','error'); }
                    });
                });
            }
        }).fail(function(xhr) {
            var msg = xhr.responseJSON?.message || ft('failed_load_data', 'Gagal memuat detail invoice.');
            $('#detailContent').html('<div class="text-center py-4 text-danger">' + esc(msg) + '</div>');
            showToast(msg, 'error');
        });
    });

    // ── Add Payment (multi-pay) ──
    window.openAddPayment = function(invoiceId, remaining) {
        let html = `
        <form id="addPayForm" enctype="multipart/form-data">
            <div class="mb-3"><label class="form-label">Jumlah Bayar</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="1" max="${remaining}" value="${remaining}" required>
                <small class="form-text invoice-amount-remaining">Sisa: Rp ${fmt(remaining)}</small>
            </div>
            <div class="mb-3"><label class="form-label">Nama Pengirim</label>
                <input type="text" name="sender_name" class="form-control" required placeholder="Nama di rekening pengirim">
            </div>
            <div class="mb-3"><label class="form-label">Bukti Transfer (Foto)</label>
                <input type="file" name="proof_file" class="form-control" accept="image/*,.pdf" required
                    data-compress-image="true" data-compress-profile="payment">
                <small class="form-text text-muted">${ft('proof_compress_hint', 'Foto otomatis dikompres saat dipilih agar upload lebih cepat.')}</small>
                <div id="proofPreview" class="mt-2"></div>
            </div>
            <input type="hidden" name="invoice_id" value="${invoiceId}">
            <button type="submit" class="btn btn-accent w-100"><i class="bi bi-upload me-1"></i>Upload Pembayaran</button>
        </form>`;

        $('#addPaymentBody').html(html);

        showStackedModal('#addPaymentModal', '#detailModal');

        setTimeout(() => {
            const $form = $('#addPayForm');
            $form.find('[name=proof_file]').off('change').on('change', async function() {
                if (window.waitForImageCompression) {
                    await window.waitForImageCompression();
                }
                const file = this.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = e => {
                    if (file.type.startsWith('image/')) {
                        const kb = Math.max(1, Math.round(file.size / 1024));
                        $('#proofPreview').html(
                            `<img src="${e.target.result}" class="img-fluid rounded" style="max-height:200px">`
                            + `<div class="small text-muted mt-1">${kb} KB</div>`
                        );
                    } else {
                        $('#proofPreview').html(`<div class="p-2 small border rounded"><i class="bi bi-file-pdf me-1"></i>${file.name}</div>`);
                    }
                };
                reader.readAsDataURL(file);
            });

            $form.off('submit').on('submit', async function(e) {
                e.preventDefault();
                if (window.waitForImageCompression) {
                    await window.waitForImageCompression();
                }
                const $btn = $form.find('button[type=submit]');
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Memproses...');
                const fd = new FormData(this);
                $.ajax({
                    url: '{{ route("billing.upload") }}', method: 'POST',
                    headers: {'X-CSRF-TOKEN': csrf},
                    data: fd, processData: false, contentType: false,
                    success(r) {
                        showToast(r.message);
                        financeCloseModal('#addPaymentModal');
                        financeRefreshList();
                        $('.btn-detail-invoice[data-id="'+invoiceId+'"]').trigger('click');
                    },
                    error(x) {
                        showToast(x.responseJSON?.message||'Gagal upload','error');
                        $btn.prop('disabled', false).html('<i class="bi bi-upload me-1"></i>Upload Pembayaran');
                    }
                });
            });
        }, 100);
    };

    // ── Review Payment (approve/reject) ──
    window.reviewPayment = function(payment) {
        window._lastPayment = payment;
        if (payment.invoice_id) {
            window._currentInvoiceId = payment.invoice_id;
        }
        $('#reviewTitle').text('Review Pembayaran');

        $('#reviewProofArea').html(renderPaymentProofHtml(payment));

        $('#reviewInfo').html(`
            <div class="row g-2 text-center">
                <div class="col-4"><div class="small" style="color:var(--text-muted)">Pengirim</div><div class="fw-700" style="color:var(--text-primary)">${payment.sender_name||'-'}</div></div>
                <div class="col-4"><div class="small" style="color:var(--text-muted)">Jumlah</div><div class="fw-700" style="color:#22c55e">Rp ${fmt(payment.amount)}</div></div>
                <div class="col-4"><div class="small" style="color:var(--text-muted)">Tanggal</div><div class="fw-600" style="color:var(--text-primary)">${new Date(payment.created_at).toLocaleDateString('id')}</div></div>
            </div>
        `);

        $('#rejectForm').addClass('d-none');
        $('#btnReviewCancel').removeClass('d-none');

        let reviewBtns = '';
        if (_canApprovePayment) {
            reviewBtns += `<button class="btn btn-accent flex-fill" onclick="approvePayment(${payment.id})"><i class="bi bi-check-lg me-1"></i>Approve</button>`;
        }
        if (_canRejectPayment) {
            reviewBtns += `<button class="btn btn-outline-danger flex-fill" onclick="showRejectForm(${payment.id})"><i class="bi bi-x-lg me-1"></i>Tolak</button>`;
        }
        $('#reviewActions').html(reviewBtns);

        showStackedModal('#reviewModal', '#detailModal');
    };

    window.approvePayment = function(id) {
        $.ajax({
            url: `/payment/${id}/approve`, method: 'POST',
            headers: {'X-CSRF-TOKEN': csrf},
            success(r) {
                showToast(r.message);
                financeCloseModal('#reviewModal');
                financeRefreshList();
                financeReloadDetailModal();
            },
            error(x) { showToast(x.responseJSON?.message||'Gagal','error'); }
        });
    };

    window.showRejectForm = function(id) {
        $('#rejectForm').removeClass('d-none');
        $('#reviewActions').html(`
            <button class="btn btn-danger flex-fill" onclick="rejectPayment(${id})"><i class="bi bi-x-lg me-1"></i>Konfirmasi Tolak</button>
            <button type="button" class="btn btn-glass flex-fill" onclick="$('#rejectForm').addClass('d-none'); reviewPayment(window._lastPayment);">${ft('cancel', 'Batal')}</button>
        `);
    };

    window.rejectPayment = function(id) {
        const notes = $('[name=review_notes]').val();
        if (!notes) { showToast('Alasan tolak wajib diisi','error'); return; }
        $.ajax({
            url: `/payment/${id}/reject`, method: 'POST',
            headers: {'X-CSRF-TOKEN': csrf},
            data: { review_notes: notes },
            success(r) {
                showToast(r.message);
                financeCloseModal('#reviewModal');
                financeRefreshList();
                financeReloadDetailModal();
            },
            error(x) { showToast(x.responseJSON?.message||'Gagal','error'); }
        });
    };

    function fmt(n) { return Number(n||0).toLocaleString('id'); }
    function formatPromoOptionValue(p) {
        if ((p.type || 'percent') === 'fixed_rp') {
            return 'Rp ' + fmt(parseFloat(p.amount_rp || 0));
        }
        return parseFloat(p.persen || 0) + '%';
    }
    function formatPromoValue(inv) {
        if ((inv.promo_type || 'percent') === 'fixed_rp') {
            return 'Rp ' + fmt(parseFloat(inv.promo_amount_rp || 0));
        }
        return parseFloat(inv.promo_persen || 0) + '%';
    }

    $(document).on('click', '.btn-review-payment', function () {
        const encoded = String($(this).data('payment') || '');
        if (!encoded) {
            showToast('Data pembayaran tidak valid.', 'error');
            return;
        }
        try {
            const parsed = JSON.parse(decodeURIComponent(encoded));
            window.reviewPayment(parsed);
        } catch (err) {
            showToast('Gagal membaca data pembayaran.', 'error');
        }
    });

    $('#btnBackfillMasukInvoice').on('click', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        Swal.fire({
            title: 'Sync invoice masuk barang?',
            html: '<p class="small text-start mb-0">Membuat invoice draft untuk barang yang sudah masuk gudang tapi belum punya tagihan. Partai belum dimuat akan tampil <strong>-</strong>.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, sync sekarang',
            cancelButtonText: 'Batal',
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sync...'
            );

            Swal.fire({
                title: 'Sedang sync invoice...',
                html: '<p class="small text-muted-glass mb-0">Mohon tunggu, proses bisa memakan beberapa detik.</p>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function () {
                    Swal.showLoading();
                },
            });

            $.ajax({
                url: @json(route('finance.invoice.backfill-masuk')),
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': @json(csrf_token()) },
                data: { limit: 200 },
            }).done(function (res) {
                const created = Number(res.created || 0);
                const remaining = Number(res.remaining || 0);
                let message = res.message || 'Sync selesai.';
                if (created > 0) {
                    message = 'Sync selesai. ' + created + ' invoice dibuat/diperbarui.'
                        + (remaining > 0 ? ' Masih ada ~' + remaining + ' barang (jalankan sync lagi jika perlu).' : '');
                }

                Swal.fire({
                    icon: created > 0 ? 'success' : 'info',
                    title: created > 0 ? 'Sync berhasil' : 'Sync selesai',
                    html: '<p class="mb-0 text-start">' + esc(message) + '</p>',
                    confirmButtonText: 'OK',
                });

                showToast(message, created > 0 ? 'success' : 'info');

                if (typeof window.refreshFinanceInvoiceList === 'function') {
                    window.refreshFinanceInvoiceList();
                }
            }).fail(function (xhr) {
                const errMsg = xhr.responseJSON?.message || 'Gagal sync invoice. Coba lagi.';
                Swal.fire({
                    icon: 'error',
                    title: 'Sync gagal',
                    text: errMsg,
                    confirmButtonText: 'OK',
                });
                showToast(errMsg, 'error');
            }).always(function () {
                $btn.prop('disabled', false).html(originalHtml);
            });
        });
    });

    function financeRefreshList() {
        if (typeof window.refreshFinanceInvoiceList === 'function') {
            window.refreshFinanceInvoiceList();
        }
    }

    function financeCloseModal(selector) {
        if (window.hideBootstrapModal) {
            window.hideBootstrapModal(selector);
            return;
        }
        financeModal(selector)?.hide();
        financeModalCleanup();
    }
});
</script>
@endpush
