(function () {
    'use strict';

    const t = (k, fb) => (window.__t && window.__t[k]) || fb;
    const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    const csrf = $('meta[name="csrf-token"]').attr('content');

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    let payoutTable = null;
    let payoutReady = false;

    function formatPeriod(period) {
        if (!period || period.indexOf('-') === -1) return period;
        const parts = period.split('-');
        const m = parseInt(parts[1], 10) - 1;
        return (months[m] || parts[1]) + ' ' + parts[0];
    }

    function currentPeriod() {
        return $('#filterCompanyPayoutPeriod').val() || window._payoutDefaultPeriod || '';
    }

    function updatePeriodLabel() {
        $('#payoutTablePeriodLabel').text('— ' + formatPeriod(currentPeriod()));
    }

    function proofCell(row) {
        if (!row.proof_url) {
            return '<span class="small" style="color:var(--text-muted)">-</span>';
        }
        return '<a href="' + row.proof_url + '" target="_blank" rel="noopener" class="btn btn-glass btn-sm py-0 px-2" title="' + t('view_proof', 'Lihat bukti') + '">'
            + '<i class="bi bi-image"></i></a>';
    }

    function refreshPayoutSummary() {
        $.get('/finance/company-revenue/payout/summary', { period: currentPeriod() }, function (res) {
            $('#statPayoutNetFix').text(fmtRp(res.fix_company));
            $('#statPayoutRemaining').text(fmtRp(res.remaining_balance));
            $('#statTotalWithdrawn').text(fmtRp(res.total_withdrawn));
        });
    }

    function loadPayoutData() {
        $.getJSON('/finance/company-revenue/payout/data', { period: currentPeriod() }, function (rows) {
            if (payoutTable) {
                payoutTable.clear().rows.add(rows).draw();
            }
            updatePeriodLabel();
        }).fail(function () {
            Swal.fire({ icon: 'error', title: t('error', 'Error'), text: t('load_failed', 'Gagal memuat') });
        });
    }

    function reloadPayoutTab() {
        refreshPayoutSummary();
        loadPayoutData();
    }

    function initPayoutTable() {
        payoutTable = $('#companyPayoutTable').DataTable({
            data: [],
            columns: [
                { data: 'payout_date', defaultContent: '-' },
                {
                    data: 'amount',
                    render: (d) => '<span class="fw-700 text-accent">' + fmtRp(d) + '</span>',
                },
                { data: null, orderable: false, render: (_, __, row) => proofCell(row) },
                {
                    data: 'note',
                    defaultContent: '-',
                    render: (d) => d ? $('<div>').text(d).html() : '-',
                },
                { data: 'created_by_name', defaultContent: '-' },
                { data: 'created_at', defaultContent: '-' },
            ],
            order: [[0, 'desc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });
    }

    function openPayoutModal() {
        $('#payoutAmount').val('');
        $('#payoutDate').val(new Date().toISOString().slice(0, 10));
        $('#payoutProofFile').val('');
        $('#payoutNote').val('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCompanyPayout')).show();
    }

    function ensurePayoutTab() {
        if (payoutReady || !$('#companyPayoutTable').length) {
            return;
        }
        payoutReady = true;
        initPayoutTable();
        reloadPayoutTab();
    }

    window.salesEarningsOnCompanyPayoutTab = function () {
        ensurePayoutTab();
        if (payoutTable) {
            payoutTable.columns.adjust().draw(false);
        }
    };

    $(function () {
        if (!$('#companyPayoutTable').length) return;

        $('#btnRefreshCompanyPayout').on('click', function () {
            ensurePayoutTab();
            reloadPayoutTab();
        });

        $('#filterCompanyPayoutPeriod').on('change', function () {
            ensurePayoutTab();
            reloadPayoutTab();
        });

        $('#btnAddCompanyPayout').on('click', function () {
            openPayoutModal();
        });

        $('#companyPayoutForm').on('submit', async function (e) {
            e.preventDefault();
            if (window.waitForImageCompression) {
                await window.waitForImageCompression();
            }
            const fd = new FormData(this);
            const $btn = $('#btnSaveCompanyPayout').prop('disabled', true);
            $.ajax({
                url: '/finance/company-revenue/payout',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': csrf },
            })
                .done(function (res) {
                    bootstrap.Modal.getInstance(document.getElementById('modalCompanyPayout'))?.hide();
                    Swal.fire({ icon: 'success', title: t('saved', 'Berhasil'), text: res.message, timer: 1500, showConfirmButton: false });
                    reloadPayoutTab();
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
