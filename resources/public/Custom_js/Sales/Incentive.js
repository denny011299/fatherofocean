(function () {
    'use strict';

    const t = (k, fb) => (window.__t && window.__t[k]) || fb;
    const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    const fmtCbm = (n) => Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 4 });

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    let incentiveTable = null;
    let incentiveReady = false;

    function formatPeriod(period) {
        if (!period || period.indexOf('-') === -1) return period;
        const parts = period.split('-');
        const m = parseInt(parts[1], 10) - 1;
        return (months[m] || parts[1]) + ' ' + parts[0];
    }

    function currentPeriod() {
        return $('#filterIncentivePeriod').val() || window._payoutDefaultPeriod || '';
    }

    function updatePeriodLabel() {
        $('#incentiveTablePeriodLabel').text('— ' + formatPeriod(currentPeriod()));
    }

    function refreshIncentiveSummary() {
        if (!$('#statIncentiveEstimasi').length) {
            return;
        }
        const period = currentPeriod();
        $.getJSON('/sales-revenue/incentive/summary', { period: period }, function (res) {
            $('#statIncentiveEstimasi').text(fmtRp(res.estimasi));
            const hint = t('stat_period_hint', 'Bulan stuffing :period').replace(':period', formatPeriod(period));
            $('#statIncentivePeriodHint').text(hint);
        });
    }

    function statusBadge(status) {
        const map = {
            estimasi: ['bg-warning text-dark', t('status_estimasi', 'Estimasi')],
            partial: ['bg-info text-dark', t('status_partial', 'Cair sebagian')],
            lunas: ['bg-success', t('status_lunas', 'Lunas insentif')],
            empty: ['bg-secondary', t('status_empty', '-')],
        };
        const cfg = map[status] || map.empty;
        return '<span class="badge ' + cfg[0] + '">' + cfg[1] + '</span>';
    }

    function actionCell(row) {
        if (row.item_count <= 0) {
            return '';
        }
        return '<button type="button" class="btn btn-glass btn-sm py-0 px-2 btn-incentive-detail" data-sales="' + row.sales_id + '" data-period="' + row.period + '" title="' + t('detail_title', 'Rincian') + '">'
            + '<i class="bi bi-list-ul"></i></button>';
    }

    function loadIncentiveData() {
        $.getJSON('/sales-revenue/incentive/data', { period: currentPeriod() }, function (rows) {
            if (incentiveTable) {
                incentiveTable.clear().rows.add(rows).draw();
            }
            updatePeriodLabel();
        }).fail(function () {
            Swal.fire({ icon: 'error', title: t('error', 'Error'), text: t('load_failed', 'Gagal memuat data insentif.') });
        });
    }

    function buildColumns() {
        const cols = [];
        if (!window._salesRevenueIsSales) {
            cols.push({ data: 'sales_name', defaultContent: '-' });
        }
        cols.push(
            { data: 'item_count', className: 'text-center', defaultContent: '0' },
            { data: 'cbm_stuffing', render: (d) => fmtCbm(d) },
            { data: 'cbm_paid', render: (d) => fmtCbm(d) },
            {
                data: 'estimasi_amount',
                render: (d) => '<span class="fw-600 text-warning">' + fmtRp(d) + '</span>',
            },
            {
                data: 'cair_amount',
                render: (d) => '<span class="fw-600 text-accent">' + fmtRp(d) + '</span>',
            },
            {
                data: 'pending_amount',
                render: (d) => '<span class="fw-600">' + fmtRp(d) + '</span>',
            },
            { data: 'payout_status', orderable: false, render: (d) => statusBadge(d) },
            { data: null, orderable: false, searchable: false, render: (_, __, row) => actionCell(row) },
        );
        return cols;
    }

    function initIncentiveTable() {
        incentiveTable = $('#salesIncentiveTable').DataTable({
            data: [],
            columns: buildColumns(),
            order: [[0, 'asc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });
    }

    function openDetail(salesId, period) {
        $.getJSON('/sales-revenue/incentive/' + salesId + '/' + period, function (res) {
            let html = '<p class="small mb-2" style="color:var(--text-muted)">'
                + t('period_label', 'Periode :period (bulan stuffing)').replace(':period', formatPeriod(period))
                + '</p>';
            html += '<div class="row g-2 mb-3 small">'
                + '<div class="col-md-4"><span style="color:var(--text-muted)">' + t('col_cbm_stuffing', 'CBM Stuffing') + '</span><br><strong>' + fmtCbm(res.cbm_stuffing) + '</strong></div>'
                + '<div class="col-md-4"><span style="color:var(--text-muted)">' + t('col_cbm_paid', 'CBM Lunas') + '</span><br><strong>' + fmtCbm(res.cbm_paid) + '</strong></div>'
                + '<div class="col-md-4"><span style="color:var(--text-muted)">' + t('blocks_stuffing', 'Kelipatan stuffing') + ' / ' + t('blocks_paid', 'Kelipatan lunas') + '</span><br><strong>'
                + (res.full_blocks_stuffing || 0) + ' / ' + (res.full_blocks_paid || 0) + '</strong></div>'
                + '</div>';
            html += '<p class="mb-1"><span class="text-warning fw-700">' + fmtRp(res.estimasi_amount) + '</span> '
                + t('col_estimasi', 'Estimasi') + ' · '
                + '<span class="text-accent fw-700">' + fmtRp(res.cair_amount) + '</span> '
                + t('col_cair', 'Sudah cair') + ' · '
                + '<span class="fw-700">' + fmtRp(res.pending_amount) + '</span> '
                + t('col_pending', 'Belum cair') + '</p>';

            if (!res.items || res.items.length === 0) {
                html += '<p class="small mt-3" style="color:var(--text-muted)">' + t('empty', 'Belum ada barang stuffing pada periode ini.') + '</p>';
            } else {
                html += '<div class="table-responsive mt-3"><table class="table-glass table-sm"><thead><tr>'
                    + '<th>No Urut</th><th>Marking</th><th>' + t('col_category', 'Kategori') + '</th><th>CBM</th><th>Lunas</th><th>Stuffing</th><th>Invoice lunas</th></tr></thead><tbody>';
                res.items.forEach(function (it) {
                    const paidLabel = it.is_paid
                        ? '<span class="badge bg-success">' + fmtCbm(it.paid_cbm) + '</span>'
                        : '<span class="badge bg-secondary">0</span>';
                    html += '<tr><td>' + (it.no_index || '-') + '</td><td>' + (it.marking || '-') + '</td><td>' + (it.pricing_item_name || '-') + '</td><td>' + fmtCbm(it.total_cbm) + '</td><td>' + paidLabel + '</td><td class="small">' + (it.stuffed_at || '-') + '</td><td class="small">' + (it.invoice_paid_at || '-') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }

            $('#incentiveDetailBody').html(html);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalIncentiveDetail')).show();
        }).fail(function () {
            Swal.fire({ icon: 'error', text: t('load_failed', 'Gagal memuat data insentif.') });
        });
    }

    function ensureIncentiveTab() {
        if (incentiveReady || !$('#salesIncentiveTable').length) {
            return;
        }
        incentiveReady = true;
        initIncentiveTable();
        loadIncentiveData();
    }

    window.salesEarningsOnIncentiveTab = function () {
        ensureIncentiveTab();
        if (incentiveTable) {
            incentiveTable.columns.adjust().draw(false);
        }
    };

    $(function () {
        if ($('#statIncentiveEstimasi').length) {
            refreshIncentiveSummary();
        }

        if (!$('#salesIncentiveTable').length) {
            return;
        }

        $('#btnRefreshIncentive').on('click', function () {
            ensureIncentiveTab();
            loadIncentiveData();
            refreshIncentiveSummary();
        });
        $('#filterIncentivePeriod').on('change', function () {
            ensureIncentiveTab();
            loadIncentiveData();
            refreshIncentiveSummary();
        });

        $(document).on('click', '.btn-incentive-detail', function () {
            openDetail($(this).data('sales'), $(this).data('period'));
        });
    });
})();
