document.addEventListener('DOMContentLoaded', function () {
    var tbody = document.getElementById('financeInvoiceTbody');
    if (!tbody || !window._financeInvoiceDataUrl) {
        return;
    }

    var $loading = $('#financeTableLoading');
    var filterTimer;
    var requestSeq = 0;
    var COLSPAN = 11;

    var paidDatePresetUi = window.DatePresetFilter
        ? window.DatePresetFilter.init({
            presetSelector: '#financeFilterPaidDatePreset',
            customRowSelector: '#financeFilterPaidDateCustomRow',
            fromSelector: '#financeFilterPaidDateFrom',
            toSelector: '#financeFilterPaidDateTo',
            onChange: function () {
                refreshInvoiceList();
            },
        })
        : null;

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString('id');
    }

    function esc(s) {
        return $('<span>').text(s == null ? '' : String(s)).html();
    }

    function paidDateFilterParams() {
        if (!window.DatePresetFilter) {
            return {};
        }

        var base = window.DatePresetFilter.getApiParams(
            '#financeFilterPaidDatePreset',
            '#financeFilterPaidDateFrom',
            '#financeFilterPaidDateTo',
            'paid_date_preset'
        );

        if (base.tanggal_from) {
            base.paid_date_from = base.tanggal_from;
            delete base.tanggal_from;
        }
        if (base.tanggal_to) {
            base.paid_date_to = base.tanggal_to;
            delete base.tanggal_to;
        }

        return base;
    }

    function filterParams() {
        var params = {
            search: ($('#financeFilterSearch').val() || '').trim(),
            status: $('#financeFilterStatus').val() || '',
            card: $('#financeFilterCard').val() || '',
        };
        var salesId = $('#financeFilterSales').val();
        if (salesId) {
            params.sales_id = salesId;
        }
        var partai = $('#financeFilterPartai').val();
        if (partai) {
            params.partai = partai;
        }

        return Object.assign(params, paidDateFilterParams());
    }

    function syncUrl(params) {
        var qs = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            if (params[k]) {
                qs.set(k, params[k]);
            }
        });
        var query = qs.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
    }

    function setTableLoading(on) {
        $loading.toggleClass('d-none', !on);
    }

    function loadingRow() {
        return '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--text-muted)">'
            + '<span class="spinner-border spinner-border-sm me-1"></span>'
            + esc(t('loading', 'Loading...'))
            + '</td></tr>';
    }

    function updateStats(stats, activeCard) {
        if (!stats) {
            return;
        }
        $('#financeStatTotal').text(stats.total ?? 0);
        $('#financeStatUnpaid').text(stats.unpaid ?? 0);
        $('#financeStatOverdue').text(stats.overdue ?? 0);
        $('#financeStatPaid').text(stats.paid ?? 0);

        $('[data-finance-card]').removeClass('stat-card-active');
        $('[data-finance-card="' + (activeCard || '') + '"]').addClass('stat-card-active');
    }

    function renderRows(rows) {
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--text-muted)">'
                + esc(t('empty_invoices', 'No invoices found.'))
                + '</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            var st = row.status || {};
            var exportBtn = '';
            if (window._canExportInvoice && window._financeExportPdfBase) {
                exportBtn = '<a href="' + esc(window._financeExportPdfBase + '/' + row.id + '/pdf')
                    + '" class="btn btn-glass btn-sm" title="PDF" target="_blank" rel="noopener">'
                    + '<i class="bi bi-cash-stack" style="color:#16a34a"></i></a>';
            }
            html += '<tr>'
                + '<td>' + esc(row.marking_label || '-') + '</td>'
                + '<td>' + esc(row.marketing_label || '-') + '</td>'
                + '<td>' + esc(row.partai_label || '-') + '</td>'
                + '<td class="fw-700" style="color:var(--text-primary)">Rp ' + fmt(row.grand_total) + '</td>'
                + '<td class="fw-600" style="color:var(--text-primary)">Rp ' + fmt(row.total_claim_amount || 0) + '</td>'
                + '<td class="invoice-amount-paid fw-600" style="color:#16a34a !important">Rp ' + fmt(row.total_paid) + '</td>'
                + '<td class="invoice-amount-remaining fw-600" style="color:#dc2626 !important">Rp ' + fmt(row.remaining) + '</td>'
                + '<td class="fw-600" style="color:var(--text-primary)">' + esc(row.due_date) + '</td>'
                + '<td class="fw-600" style="color:var(--text-primary)">' + esc(row.tanggal_lunas || '—') + '</td>'
                + '<td class="finance-col-status"><span class="finance-status-badge ' + esc(st.class || 'badge-glass') + '">'
                + esc(st.label || '') + '</span></td>'
                + '<td><div class="d-flex gap-1">'
                + '<button type="button" class="btn btn-accent btn-sm btn-detail-invoice" data-id="' + row.id + '" title="Detail & Pembayaran">'
                + '<i class="bi bi-eye"></i></button>'
                + exportBtn
                + '</div></td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    }

    function refreshInvoiceList() {
        var params = filterParams();
        syncUrl(params);
        var seq = ++requestSeq;
        setTableLoading(true);

        $.getJSON(window._financeInvoiceDataUrl, params)
            .done(function (payload) {
                if (seq !== requestSeq) {
                    return;
                }
                var rows = Array.isArray(payload) ? payload : (payload.rows || []);
                updateStats(payload.stats, payload.active_card ?? params.card);
                renderRows(rows);
            })
            .fail(function () {
                if (seq !== requestSeq) {
                    return;
                }
                tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4 text-danger">'
                    + esc(t('load_failed', 'Failed to load data.'))
                    + '</td></tr>';
            })
            .always(function () {
                if (seq === requestSeq) {
                    setTableLoading(false);
                }
            });
    }

    window.refreshFinanceInvoiceList = refreshInvoiceList;

    $('#financeFilterSearch').on('input', function () {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(refreshInvoiceList, 350);
    });

    $('#financeFilterStatus').on('change', refreshInvoiceList);
    $('#financeFilterSales').on('change', refreshInvoiceList);
    $('#financeFilterPartai').on('change', refreshInvoiceList);

    $(document).on('click', '[data-finance-card]', function () {
        var card = $(this).attr('data-finance-card') || '';
        var current = $('#financeFilterCard').val() || '';
        $('#financeFilterCard').val(current === card && card ? '' : card);
        refreshInvoiceList();
    });

    $('#btnExportFinanceInvoices').on('click', function () {
        var params = new URLSearchParams(filterParams());
        var qs = params.toString();
        window.location.href = window._financeInvoiceExportUrl + (qs ? '?' + qs : '');
    });

    if (paidDatePresetUi && ($('#financeFilterPaidDatePreset').val() || '') === 'custom') {
        window.DatePresetFilter.toggleCustomRow($('#financeFilterPaidDateCustomRow'), true);
    }
});
