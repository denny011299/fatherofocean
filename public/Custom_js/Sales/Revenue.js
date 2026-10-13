(function () {
    'use strict';

    const t = (k, fb) => (window.__t && window.__t[k]) || fb;
    const fmtRp = (n) => 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
    const fmtCbm = (n) => Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    const fmtKg = (n) => Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const csrf = $('meta[name="csrf-token"]').attr('content');
    const page = window._salesRevenuePage || 'sales';
    let table = null;
    let companyTable = null;
    let detailContext = null;
    let detailEditSource = null;
    let reopenDetailAfterPayout = false;
    let refreshDetailAfterPayout = false;

    const fixAmount = (row) => (row.is_fixed ? (row.confirmed_amount ?? row.estimated_amount) : null);

    function escAttr(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    const paymentStatusBadge = (row) => {
        if (row.is_paid || row.payment_status === 'paid' || row.own_invoice_paid) {
            return '<span class="badge bg-success">' + t('payment_paid', 'Lunas') + '</span>';
        }
        if (row.payment_status === 'partial') {
            return '<span class="badge bg-warning text-dark">' + t('payment_partial', 'Sebagian bayar') + '</span>';
        }
        return '<span class="badge bg-secondary">' + t('payment_unpaid', 'Belum') + '</span>';
    };

    const commissionStatusBadge = (row) => {
        const paid = row.commission_paid
            || row.commission_status === 'paid'
            || row.commission_payment_status === 'paid';
        if (paid) {
            return '<span class="badge bg-success">' + t('commission_status_paid', 'Sudah') + '</span>';
        }
        return '<span class="badge bg-secondary">' + t('commission_status_unpaid', 'Belum Terbayarkan') + '</span>';
    };

    function commissionStatusLabel(status) {
        if (status === 'paid') {
            return '<span class="badge bg-success">' + t('commission_status_paid', 'Sudah') + '</span>';
        }
        return '<span class="badge bg-secondary">' + t('commission_status_unpaid', 'Belum Terbayarkan') + '</span>';
    }

    const actionCell = (row) => {
        return '<button type="button" class="btn btn-accent btn-sm py-0 px-2 btn-commission-detail" '
            + 'data-sales="' + row.sales_id + '" data-marking="' + escAttr(row.marking) + '" '
            + 'title="' + t('commission_detail_title', 'Detail Komisi') + '">'
            + '<i class="bi bi-eye"></i></button>';
    };

    function setTableLoading(loading) {
        const $el = $('#salesRevenueTableLoading');
        if (!$el.length) {
            return;
        }
        $el.toggleClass('d-none', !loading).attr('aria-hidden', loading ? 'false' : 'true');
        $('#btnRefreshSalesRevenue').prop('disabled', !!loading);
    }

    function readPartaiFilter() {
        let value = $('#filterCommissionPartai').val();
        if (Array.isArray(value)) {
            value = value.length ? value[0] : '';
        }
        return String(value || '').trim();
    }

    function filterParams() {
        return {
            status: $('#filterCommissionStatus').val() || '',
            date_from: $('#filterDateFrom').val() || '',
            date_to: $('#filterDateTo').val() || '',
            partai: readPartaiFilter(),
        };
    }

    function rowMatchesPartaiFilter(row, partai) {
        if (!partai) {
            return true;
        }
        const label = String(row.partai || '').trim();
        if (partai === 'belum_di_muat') {
            return label === '';
        }
        return label === partai;
    }

    function refreshSummary() {
        $.get('/sales-revenue/summary', filterParams(), function (totals) {
            if ($('#statEstimasi').length) {
                $('#statEstimasi').text(fmtRp(totals.estimasi));
                $('#statFix').text(fmtRp(totals.fix));
            }
            if ($('#statEstimasiCompany').length) {
                $('#statEstimasiCompany').text(fmtRp(totals.estimasi_company));
                $('#statFixCompany').text(fmtRp(totals.fix_company));
            }
        });
    }

    function loadData() {
        setTableLoading(true);
        $.get('/sales-revenue/data', filterParams(), function (rows) {
            if (table) {
                table.clear().rows.add(rows).draw();
            }
            refreshSummary();
        }).fail(function () {
            Swal.fire({ icon: 'error', title: t('error', 'Error'), text: t('load_failed', 'Gagal memuat data') });
        }).always(function () {
            setTableLoading(false);
        });
    }

    function loadCompanyData() {
        if (!companyTable) {
            return;
        }
        $.get('/finance/earnings/company-data', filterParams(), function (rows) {
            companyTable.clear().rows.add(rows).draw();
            refreshSummary();
        });
    }

    function paymentStatusLabel(status) {
        if (status === 'paid') {
            return '<span class="badge bg-success">' + t('payment_paid', 'Lunas') + '</span>';
        }
        if (status === 'partial') {
            return '<span class="badge bg-warning text-dark">' + t('payment_partial', 'Sebagian bayar') + '</span>';
        }
        return '<span class="badge bg-secondary">' + t('payment_unpaid', 'Belum') + '</span>';
    }

    function updateDetailRemainingUi() {
        if (!detailContext) {
            return;
        }
        const totalKomisi = Math.round(Number($('#detailTotalKomisiInput').val() || detailContext.total_komisi || 0));
        const totalPaid = Number(detailContext.total_paid || 0);
        const remaining = Math.max(0, totalKomisi - totalPaid);
        $('#detailRemainingValue').text(fmtRp(remaining));
        detailContext.total_komisi = totalKomisi;
        detailContext.remaining = remaining;
        const rate = Number($('#detailCommissionRateInput').val() || detailContext.commission_rate || 0);
        detailContext.commission_rate = rate;
        const commissionPaid = totalKomisi > 0 && totalPaid >= totalKomisi;
        $('#detailCommissionStatusBadge').html(commissionStatusLabel(commissionPaid ? 'paid' : 'unpaid'));
    }

    function bindDetailKomisiEditors() {
        const canEdit = !!(detailContext && detailContext.can_edit_komisi);
        if (!canEdit) {
            return;
        }

        $('#detailCommissionRateInput').on('input', function () {
            if (detailEditSource === 'komisi') {
                return;
            }
            detailEditSource = 'rate';
            const rate = Math.max(0, Number($(this).val() || 0));
            const omset = Number(detailContext.total_omset || 0);
            const total = Math.round(omset * rate / 100);
            $('#detailTotalKomisiInput').val(total);
            updateDetailRemainingUi();
            detailEditSource = null;
        });

        $('#detailTotalKomisiInput').on('input', function () {
            if (detailEditSource === 'rate') {
                return;
            }
            detailEditSource = 'komisi';
            const total = Math.max(0, Math.round(Number($(this).val() || 0)));
            const omset = Number(detailContext.total_omset || 0);
            if (omset > 0) {
                const rate = Math.round((total / omset) * 10000) / 100;
                $('#detailCommissionRateInput').val(rate.toFixed(2));
            }
            updateDetailRemainingUi();
            detailEditSource = null;
        });

        $('#btnSaveCommissionOverride').on('click', function () {
            const $btn = $(this).prop('disabled', true);
            const filters = filterParams();
            $.ajax({
                url: '/sales-revenue/commission-override',
                method: 'POST',
                data: {
                    sales_id: detailContext.sales_id,
                    marking: detailContext.marking,
                    commission_rate: $('#detailCommissionRateInput').val(),
                    total_komisi: $('#detailTotalKomisiInput').val(),
                    date_from: filters.date_from,
                    date_to: filters.date_to,
                    partai: filters.partai,
                    _token: csrf,
                },
            })
                .done(function (res) {
                    Swal.fire({
                        icon: 'success',
                        title: t('saved', 'Berhasil'),
                        text: res.message || t('komisi_override_saved', 'Persenan/total komisi berhasil disimpan.'),
                        timer: 1500,
                        showConfirmButton: false,
                    });
                    if (res.detail) {
                        renderCommissionDetail(res.detail);
                    }
                    loadData();
                })
                .fail(function (xhr) {
                    Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || t('save_failed', 'Gagal') });
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    }

    function renderCommissionDetail(res) {
        detailContext = res;
        detailEditSource = null;
        const canEdit = !!res.can_edit_komisi;
        const invoiceStatus = res.invoice_status || (res.invoice_paid ? 'paid' : 'unpaid');
        const commissionStatus = res.commission_status || res.status || (res.commission_paid || res.is_paid ? 'paid' : 'unpaid');

        let html = '<div class="row g-3 mb-4">'
            + '<div class="col-md-3"><div class="small" style="color:var(--text-muted)">' + t('detail_marketing', 'Marketing') + '</div>'
            + '<div class="fw-700">' + (res.sales_name || '-') + '</div></div>'
            + '<div class="col-md-3"><div class="small" style="color:var(--text-muted)">' + t('detail_marking', 'Marking') + '</div>'
            + '<div class="fw-700">' + (res.marking || '-') + '</div></div>'
            + '<div class="col-md-2"><div class="small" style="color:var(--text-muted)">' + t('detail_pic', 'PIC') + '</div>'
            + '<div class="fw-600">' + (res.pic || res.customer_name || '-') + '</div></div>'
            + '<div class="col-md-2"><div class="small" style="color:var(--text-muted)">' + t('detail_status_invoice', 'Status Invoice') + '</div>'
            + '<div>' + paymentStatusLabel(invoiceStatus) + '</div></div>'
            + '<div class="col-md-2"><div class="small" style="color:var(--text-muted)">' + t('detail_status_komisi', 'Status Komisi') + '</div>'
            + '<div id="detailCommissionStatusBadge">' + commissionStatusLabel(commissionStatus) + '</div></div>'
            + '</div>';

        html += '<div class="glass-solid p-3 mb-4">'
            + '<div class="d-flex flex-wrap align-items-end gap-3">'
            + '<div><span class="small" style="color:var(--text-muted)">' + t('detail_total_omset', 'Total Omset') + '</span>'
            + '<div class="fw-700">' + fmtRp(res.total_omset) + '</div></div>'
            + '<div class="fs-4 text-muted pb-1">×</div>'
            + '<div><label class="small mb-1" style="color:var(--text-muted)" for="detailCommissionRateInput">'
            + t('detail_commission_rate', 'Persenan') + '</label>';
        if (canEdit) {
            html += '<div class="input-group input-group-sm" style="width:120px">'
                + '<input type="number" class="form-control" id="detailCommissionRateInput" min="0" max="100" step="0.01" value="'
                + Number(res.commission_rate || 0).toFixed(2) + '">'
                + '<span class="input-group-text">%</span></div>';
        } else {
            html += '<div class="fw-700">' + Number(res.commission_rate || 0).toFixed(2) + '%</div>';
        }
        html += '</div>'
            + '<div class="fs-4 text-muted pb-1">=</div>'
            + '<div><label class="small mb-1" style="color:var(--text-muted)" for="detailTotalKomisiInput">'
            + t('detail_total_komisi', 'Total Komisi') + '</label>';
        if (canEdit) {
            html += '<input type="number" class="form-control form-control-sm text-accent fw-800" id="detailTotalKomisiInput" '
                + 'min="0" step="1" value="' + Math.round(Number(res.total_komisi || 0)) + '" style="width:160px">';
        } else {
            html += '<div class="fw-800 text-accent">' + fmtRp(res.total_komisi) + '</div>';
        }
        html += '</div>';
        if (canEdit) {
            html += '<div class="pb-1"><button type="button" class="btn btn-accent btn-sm" id="btnSaveCommissionOverride">'
                + '<i class="bi bi-check2 me-1"></i>' + t('btn_save_komisi', 'Simpan Komisi') + '</button></div>';
        }
        html += '</div>'
            + '<div class="d-flex flex-wrap gap-3 mt-3 small">'
            + '<span>' + t('detail_total_paid', 'Sudah dibayar') + ': <strong>' + fmtRp(res.total_paid) + '</strong></span>'
            + '<span>' + t('detail_remaining', 'Sisa komisi') + ': <strong class="text-warning" id="detailRemainingValue">'
            + fmtRp(res.remaining) + '</strong></span>'
            + '</div></div>';

        html += '<h6 class="fw-700 mb-2">' + t('detail_items', 'Item komisi') + '</h6>';
        html += '<div class="table-responsive mb-4"><table class="table-glass table-sm"><thead><tr>'
            + '<th>No Urut</th><th>' + t('detail_item', 'Item') + '</th><th>' + t('detail_cbm', 'Kubikasi') + '</th>'
            + '<th>' + t('detail_weight', 'Berat') + '</th><th>Partai</th><th>Omset</th><th>Komisi</th>'
            + '<th>' + t('detail_status_invoice', 'Status Invoice') + '</th></tr></thead><tbody>';

        if (!res.items || res.items.length === 0) {
            html += '<tr><td colspan="8" class="text-center py-3" style="color:var(--text-muted)">' + t('empty', 'Belum ada data') + '</td></tr>';
        } else {
            res.items.forEach(function (it) {
                const itemInvoice = it.own_invoice_paid ? 'paid' : 'unpaid';
                html += '<tr><td>' + (it.no_index || '-') + '</td><td>' + $('<div>').text(it.item || '-').html() + '</td>'
                    + '<td>' + fmtCbm(it.cbm) + '</td><td>' + fmtKg(it.berat) + ' kg</td><td>' + (it.partai || '-') + '</td>'
                    + '<td>' + fmtRp(it.omset) + '</td><td class="fw-600">' + fmtRp(it.komisi) + '</td>'
                    + '<td>' + paymentStatusLabel(itemInvoice) + '</td></tr>';
            });
        }
        html += '</tbody></table></div>';

        html += '<div class="d-flex justify-content-between align-items-center mb-2">'
            + '<h6 class="fw-700 mb-0">' + t('detail_payout_history', 'Riwayat Pay Out') + '</h6>';
        if (window._canUploadSalaryProof && res.remaining > 0 && res.total_komisi > 0) {
            html += '<button type="button" class="btn btn-accent btn-sm" id="btnOpenCommissionPayout">'
                + '<i class="bi bi-plus-circle me-1"></i>' + t('btn_add_payment', 'Tambah Bayar') + '</button>';
        }
        html += '</div>';

        html += '<div class="table-responsive"><table class="table-glass table-sm"><thead><tr>'
            + '<th>Tanggal</th><th>Nominal</th><th>Bukti</th><th>Catatan</th><th>Oleh</th></tr></thead><tbody>';

        if (!res.payouts || res.payouts.length === 0) {
            html += '<tr><td colspan="5" class="text-center py-3" style="color:var(--text-muted)">-</td></tr>';
        } else {
            res.payouts.forEach(function (p) {
                const proof = p.proof_url
                    ? '<a href="' + p.proof_url + '" target="_blank" rel="noopener" class="btn btn-glass btn-sm py-0 px-2"><i class="bi bi-image"></i></a>'
                    : '-';
                html += '<tr><td>' + (p.payout_date || '-') + '</td><td class="fw-600">' + fmtRp(p.amount) + '</td>'
                    + '<td>' + proof + '</td><td>' + (p.note ? $('<div>').text(p.note).html() : '-') + '</td>'
                    + '<td class="small">' + (p.created_by_name || '-') + '</td></tr>';
            });
        }
        html += '</tbody></table></div>';

        $('#commissionDetailBody').html(html);
        bindDetailKomisiEditors();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCommissionDetail')).show();
    }

    function openCommissionDetail(salesId, marking) {
        const params = Object.assign({ sales_id: salesId, marking: marking }, filterParams());
        $.getJSON('/sales-revenue/commission-detail', params, function (res) {
            renderCommissionDetail(res);
        }).fail(function (xhr) {
            Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || t('load_failed', 'Gagal memuat') });
        });
    }

    function openCommissionPayoutModal() {
        if (!detailContext) {
            return;
        }
        const filters = filterParams();
        $('#commissionPayoutSalesId').val(detailContext.sales_id);
        $('#commissionPayoutMarking').val(detailContext.marking);
        $('#commissionPayoutDateFrom').val(filters.date_from);
        $('#commissionPayoutDateTo').val(filters.date_to);
        $('#commissionPayoutPartai').val(filters.partai);
        $('#commissionPayoutMeta').text((detailContext.sales_name || '-') + ' · ' + (detailContext.marking || '-'));
        $('#commissionPayoutRemaining').text(t('detail_remaining', 'Sisa komisi') + ': ' + fmtRp(detailContext.remaining));
        $('#commissionPayoutAmount').val(Math.round(detailContext.remaining || 0));
        $('#commissionPayoutDate').val(new Date().toISOString().slice(0, 10));
        $('#commissionPayoutProof').val('');
        $('#commissionPayoutNote').val('');

        const detailEl = document.getElementById('modalCommissionDetail');
        const payoutEl = document.getElementById('modalCommissionPayout');
        if (!detailEl || !payoutEl) {
            return;
        }

        reopenDetailAfterPayout = true;
        refreshDetailAfterPayout = false;

        const payoutModal = function () {
            if (window.getBootstrapModal) {
                return window.getBootstrapModal(payoutEl, { backdrop: true, keyboard: true });
            }
            return bootstrap.Modal.getOrCreateInstance(payoutEl, { backdrop: true, keyboard: true });
        };

        const ensurePayoutBackdrop = function () {
            if (!document.querySelector('.modal-backdrop')) {
                const bd = document.createElement('div');
                bd.className = 'modal-backdrop fade show';
                document.body.appendChild(bd);
            }
            document.body.classList.add('modal-open');
        };

        const showPayout = function () {
            detailEl.removeEventListener('hidden.bs.modal', showPayout);
            // Wait past syncBootstrapModalState (setTimeout 0) so it does not
            // wipe the new backdrop before modalCommissionPayout gets .show.
            setTimeout(function () {
                payoutEl.addEventListener('shown.bs.modal', ensurePayoutBackdrop, { once: true });
                payoutModal().show();
            }, 50);
        };

        // Hide Detail Komisi first so only one modal/backdrop is visible.
        if (detailEl.classList.contains('show')) {
            detailEl.addEventListener('hidden.bs.modal', showPayout);
            bootstrap.Modal.getOrCreateInstance(detailEl).hide();
        } else {
            showPayout();
        }
    }

    function restoreCommissionDetailAfterPayout() {
        if (!reopenDetailAfterPayout) {
            return;
        }
        reopenDetailAfterPayout = false;

        const salesId = detailContext?.sales_id;
        const marking = detailContext?.marking;
        const shouldRefresh = refreshDetailAfterPayout;
        refreshDetailAfterPayout = false;

        if (shouldRefresh && salesId && marking != null) {
            setTimeout(function () {
                openCommissionDetail(salesId, marking);
            }, 50);
            return;
        }

        const detailEl = document.getElementById('modalCommissionDetail');
        if (detailEl) {
            setTimeout(function () {
                bootstrap.Modal.getOrCreateInstance(detailEl).show();
            }, 50);
        }
    }

    function initSalesTable() {
        const showSales = !window._salesRevenueIsSales;
        const showSupervisor = window._salesRevenueShowSupervisorCol;
        // Explicit titles so DataTables cannot keep a stale thead ("STATUS BAYAR") from cache/collision.
        const cols = [
            { data: 'no_index', defaultContent: '-', title: t('col_no_index', 'No. Urut') },
            { data: 'tanggal_masuk', defaultContent: '-', title: t('col_date', 'Tgl Masuk') },
            { data: 'marking', defaultContent: '-', title: t('col_marking', 'Marking') },
            {
                data: 'partai',
                defaultContent: '-',
                title: t('col_partai', 'Partai'),
                render: (d) => d || t('partai_not_loaded', 'Belum dimuat'),
            },
        ];
        if (showSales) {
            cols.push({ data: 'sales_name', defaultContent: '-', title: t('col_sales', 'Sales') });
        }
        cols.push(
            { data: 'total_cbm', title: t('col_total_cbm', 'Total CBM'), render: (d) => fmtCbm(d) },
            { data: 'sales_commission_base', title: t('col_omset', 'Omset'), render: (d) => fmtRp(d) },
            { data: 'commission_rate', title: '%', render: (d) => Number(d || 0).toFixed(2) + '%' },
            { data: 'estimated_amount', title: t('col_estimasi', 'Estimasi'), render: (d) => fmtRp(d) },
            {
                data: null,
                title: t('col_fix', 'Fix'),
                render: (_, __, row) => {
                    const fix = fixAmount(row);
                    return fix != null ? fmtRp(fix) : '-';
                },
            },
        );
        if (showSupervisor) {
            cols.push({
                data: 'supervisor_amount',
                title: t('col_supervisor', 'Capital Gain (Supervisor)'),
                render: (d) => fmtRp(d),
            });
        }
        cols.push(
            {
                data: null,
                orderable: false,
                title: t('col_invoice_status', t('col_payment_status', 'Status Invoice')),
                render: (_, __, row) => paymentStatusBadge(row),
            },
            {
                data: null,
                orderable: false,
                title: t('col_commission_status', 'Status Komisi'),
                render: (_, __, row) => commissionStatusBadge(row),
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                title: t('actions', 'Aksi'),
                render: (_, __, row) => actionCell(row),
            },
        );

        table = $('#salesRevenueTable').DataTable({
            data: [],
            columns: cols,
            order: [[0, 'desc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });

        // AND partai dropdown with DataTables search box (server also filters; this is a safety net).
        if (!window._salesRevenuePartaiSearchRegistered) {
            window._salesRevenuePartaiSearchRegistered = true;
            $.fn.dataTable.ext.search.push(function (settings, _data, dataIndex) {
                if (!settings.nTable || settings.nTable.id !== 'salesRevenueTable') {
                    return true;
                }
                if (!table) {
                    return true;
                }
                const partai = readPartaiFilter();
                if (!partai) {
                    return true;
                }
                const row = table.row(dataIndex).data();
                return rowMatchesPartaiFilter(row || {}, partai);
            });
        }

        loadData();

        window.salesEarningsOnCommissionTab = function () {
            if (table) {
                table.columns.adjust().draw(false);
            }
        };
    }

    function initCompanyTable() {
        companyTable = $('#companyRevenueTable').DataTable({
            data: [],
            columns: [
                { data: 'no_index', defaultContent: '-' },
                { data: 'tanggal_masuk', defaultContent: '-' },
                { data: 'marking', defaultContent: '-' },
                { data: 'billable_base', render: (d) => fmtRp(d) },
                { data: 'company_profit', render: (d) => fmtRp(d) },
                {
                    data: null,
                    render: (_, __, row) => (!row.is_fixed ? fmtRp(row.company_profit) : '-'),
                },
                {
                    data: null,
                    render: (_, __, row) => (row.is_fixed ? fmtRp(row.company_profit) : '-'),
                },
            ],
            order: [[0, 'desc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });

        loadCompanyData();
    }

    $(function () {
        if (page === 'company') {
            initCompanyTable();
        } else if ($('#salesRevenueTable').length) {
            initSalesTable();
        }

        if ($.fn.select2 && $('#filterCommissionPartai').length) {
            $('#filterCommissionPartai').select2({
                width: '100%',
                allowClear: true,
                placeholder: t('filter_all_partai', 'Semua Partai'),
            });
        }

        $('#btnRefreshSalesRevenue').on('click', function () {
            if (page === 'company') {
                loadCompanyData();
            } else {
                loadData();
            }
        });
        $('#filterCommissionStatus, #filterDateFrom, #filterDateTo').on('change', function () {
            if (page === 'company') {
                loadCompanyData();
            } else {
                loadData();
            }
        });
        $('#filterCommissionPartai').on('change select2:select select2:clear', function () {
            if (page === 'company') {
                loadCompanyData();
            } else {
                loadData();
            }
        });

        $('#btnExportSalesRevenue').on('click', function () {
            const params = new URLSearchParams(filterParams());
            const qs = params.toString();
            window.location.href = '/sales-revenue/export' + (qs ? '?' + qs : '');
        });

        $(document).on('click', '.btn-commission-detail', function () {
            openCommissionDetail(parseInt($(this).data('sales'), 10), $(this).attr('data-marking') || '');
        });

        $(document).on('click', '#btnOpenCommissionPayout', function () {
            openCommissionPayoutModal();
        });

        $('#modalCommissionPayout').on('hidden.bs.modal', function () {
            restoreCommissionDetailAfterPayout();
        });

        $('#commissionPayoutForm').on('submit', async function (e) {
            e.preventDefault();
            if (window.waitForImageCompression) {
                await window.waitForImageCompression();
            }
            const fd = new FormData(this);
            const $btn = $('#btnSaveCommissionPayout').prop('disabled', true);
            $.ajax({
                url: '/sales-revenue/commission-payout',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': csrf },
            })
                .done(function (res) {
                    refreshDetailAfterPayout = true;
                    bootstrap.Modal.getInstance(document.getElementById('modalCommissionPayout'))?.hide();
                    Swal.fire({ icon: 'success', title: t('saved', 'Berhasil'), text: res.message, timer: 1500, showConfirmButton: false });
                    loadData();
                })
                .fail(function (xhr) {
                    Swal.fire({ icon: 'error', text: xhr.responseJSON?.message || t('save_failed', 'Gagal') });
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    });
})();
