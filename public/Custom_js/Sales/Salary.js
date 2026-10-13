(function () {
    'use strict';

    const t = (k, fb) => (window.__t && window.__t[k]) || fb;
    const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    const csrf = $('meta[name="csrf-token"]').attr('content');

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    let salaryTable = null;
    let salaryReady = false;
    let uploadSalesId = null;
    let uploadPeriod = null;

    function formatPeriod(period) {
        if (!period || period.indexOf('-') === -1) return period;
        const parts = period.split('-');
        const m = parseInt(parts[1], 10) - 1;
        return (months[m] || parts[1]) + ' ' + parts[0];
    }

    function currentPeriod() {
        return $('#filterPayoutPeriod').val() || window._payoutDefaultPeriod || '';
    }

    function updatePeriodLabel() {
        $('#salaryTablePeriodLabel').text('— ' + formatPeriod(currentPeriod()));
    }

    function statusCell(row) {
        if (row.is_settled) {
            return '<span class="badge bg-success">' + t('status_net_fix', 'Net fix') + '</span>';
        }
        return '<span class="badge bg-secondary">' + t('status_belum_lunas', 'Belum lunas') + '</span>';
    }

    function proofCell(row) {
        if (!window._canUploadSalaryProof && !row.proof_url) {
            return '<span class="small" style="color:var(--text-muted)">-</span>';
        }
        let html = '<div class="d-inline-flex gap-1">';
        if (row.proof_url) {
            html += '<a href="' + row.proof_url + '" target="_blank" rel="noopener" class="btn btn-glass btn-sm py-0 px-2" title="' + t('view_proof_short', 'Lihat') + '">'
                + '<i class="bi bi-image"></i></a>';
        }
        if (window._canUploadSalaryProof && row.is_settled) {
            const title = row.proof_url ? t('replace_proof_short', 'Ganti') : t('upload_proof_short', 'Upload');
            html += '<button type="button" class="btn btn-accent btn-sm py-0 px-2 btn-salary-upload" data-sales="' + row.sales_id + '" data-period="' + row.period + '" title="' + title + '">'
                + '<i class="bi bi-upload"></i></button>';
        }
        html += '</div>';
        return html || '<span class="small" style="color:var(--text-muted)">-</span>';
    }

    function actionCell(row) {
        return '<button type="button" class="btn btn-glass btn-sm py-0 px-2 btn-salary-detail" data-sales="' + row.sales_id + '" data-period="' + row.period + '" title="' + t('detail_title', 'Rincian') + '">'
            + '<i class="bi bi-list-ul"></i></button>';
    }

    function loadSalaryData() {
        $.getJSON('/sales-revenue/salary/data', { period: currentPeriod() }, function (rows) {
            if (salaryTable) {
                salaryTable.clear().rows.add(rows).draw();
            }
            updatePeriodLabel();
        }).fail(function () {
            Swal.fire({ icon: 'error', title: t('error', 'Error'), text: t('load_failed', 'Gagal memuat') });
        });
    }

    function initSalaryTable() {
        salaryTable = $('#salesSalaryTable').DataTable({
            data: [],
            columns: [
                { data: 'sales_name', defaultContent: '-' },
                { data: 'item_count', className: 'text-center', defaultContent: '0' },
                {
                    data: 'total_amount',
                    render: (d, _, row) => {
                        const cls = row.is_settled ? 'fw-700 text-accent' : 'fw-600';
                        return '<span class="' + cls + '">' + fmtRp(d) + '</span>';
                    },
                },
                { data: null, orderable: false, render: (_, __, row) => statusCell(row) },
                { data: null, orderable: false, render: (_, __, row) => proofCell(row) },
                { data: 'proof_uploaded_at', defaultContent: '-', render: (d) => d || '-' },
                { data: null, orderable: false, searchable: false, render: (_, __, row) => actionCell(row) },
            ],
            order: [[0, 'asc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });
    }

    function openDetail(salesId, period) {
        $.getJSON('/sales-revenue/salary/' + salesId + '/' + period, function (res) {
            let html = '<p class="small mb-2" style="color:var(--text-muted)">' + t('period_label', 'Periode :period').replace(':period', formatPeriod(period)) + '</p>';
            html += '<p class="fw-700 mb-3">' + fmtRp(res.total_amount) + '</p>';
            if (!res.items || res.items.length === 0) {
                html += '<p class="small" style="color:var(--text-muted)">' + t('empty', 'Belum ada komisi net fix pada periode ini.') + '</p>';
            } else {
                html += '<div class="table-responsive"><table class="table-glass table-sm"><thead><tr>'
                    + '<th>No Urut</th><th>Marking</th><th>Partai</th><th>Nominal</th><th>Status</th><th>Tgl</th></tr></thead><tbody>';
                res.items.forEach(function (it) {
                    html += '<tr><td>' + (it.no_index || '-') + '</td><td>' + (it.marking || '-') + '</td><td>'
                        + (it.partai || '-') + '</td><td>' + fmtRp(it.confirmed_amount) + '</td><td>' + it.status
                        + '</td><td class="small">' + (it.confirmed_at || '-') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            $('#salaryDetailBody').html(html);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSalaryDetail')).show();
        });
    }

    function openUpload(salesId, period) {
        uploadSalesId = salesId;
        uploadPeriod = period;
        $('#salaryUploadPeriod').text(formatPeriod(period));
        $('#salaryProofFile').val('');
        $('#salaryAdminNote').val('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSalaryUpload')).show();
    }

    function ensureSalaryTab() {
        if (salaryReady || !$('#salesSalaryTable').length) {
            return;
        }
        salaryReady = true;
        initSalaryTable();
        loadSalaryData();
    }

    window.salesEarningsOnSalaryTab = function () {
        ensureSalaryTab();
        if (salaryTable) {
            salaryTable.columns.adjust().draw(false);
        }
    };

    $(function () {
        if (!$('#salesSalaryTable').length) return;

        $('#btnRefreshPayout').on('click', function () {
            ensureSalaryTab();
            loadSalaryData();
        });
        $('#filterPayoutPeriod').on('change', function () {
            ensureSalaryTab();
            loadSalaryData();
        });

        $(document).on('click', '.btn-salary-detail', function () {
            openDetail($(this).data('sales'), $(this).data('period'));
        });

        $(document).on('click', '.btn-salary-upload', function () {
            openUpload($(this).data('sales'), $(this).data('period'));
        });

        $('#salaryUploadForm').on('submit', async function (e) {
            e.preventDefault();
            if (!uploadSalesId || !uploadPeriod) return;
            if (window.waitForImageCompression) {
                await window.waitForImageCompression();
            }
            const fd = new FormData(this);
            const $btn = $('#btnSaveSalaryProof').prop('disabled', true);
            $.ajax({
                url: '/sales-revenue/salary/' + uploadSalesId + '/' + uploadPeriod + '/proof',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': csrf },
            })
                .done(function (res) {
                    bootstrap.Modal.getInstance(document.getElementById('modalSalaryUpload'))?.hide();
                    Swal.fire({ icon: 'success', title: t('saved', 'Berhasil'), text: res.message, timer: 1500, showConfirmButton: false });
                    loadSalaryData();
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
