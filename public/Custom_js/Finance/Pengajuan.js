document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('pengajuanTable')) {
        return;
    }

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    var table;

    initTable();
    refreshData();
    refreshNavBadge();

    window.financePengajuanOnTabShow = function () {
        if (table) {
            table.columns.adjust().draw(false);
        }
    };

    $('#filterStatus').on('change', refreshData);
    $('#btnRefreshPengajuan').on('click', refreshData);

    $(document).on('click', '.btn-pengajuan-approve', function () {
        var id = $(this).data('request-id');
        var idx = $(this).data('index');
        Swal.fire({
            title: t('repricing_confirm_approve_title', 'Approve repricing?'),
            text: (t('repricing_confirm_approve_text', 'Apply proposed price for :index?') || '').replace(':index', idx),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: t('repricing_approve', 'Approve'),
            cancelButtonText: t('cancel', 'Cancel'),
            confirmButtonColor: 'var(--accent, #818cf8)',
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }
            $.post('/finance/repricing/' + id + '/approve', function (res) {
                showToast(res.message || t('repricing_approved', 'Approved'));
                refreshData();
                refreshNavBadge();
            }).fail(function (xhr) {
                showToast(xhr.responseJSON?.message || t('generic_failed', 'Failed'), 'error');
            });
        });
    });

    $(document).on('click', '.btn-pengajuan-reject', function () {
        var id = $(this).data('request-id');
        var idx = $(this).data('index');
        Swal.fire({
            title: t('repricing_confirm_reject_title', 'Reject repricing?'),
            input: 'textarea',
            inputLabel: t('repricing_reject_reason', 'Rejection reason'),
            inputPlaceholder: t('reject_reason_placeholder', 'Explain the reason...'),
            inputAttributes: { maxlength: 500 },
            showCancelButton: true,
            confirmButtonText: t('repricing_reject', 'Reject'),
            cancelButtonText: t('cancel', 'Cancel'),
            confirmButtonColor: '#fb7185',
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return t('reject_reason_required', 'Rejection reason is required');
                }
            },
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }
            $.post('/finance/repricing/' + id + '/reject', { rejection_note: result.value }, function (res) {
                showToast(res.message || t('repricing_rejected', 'Rejected'));
                refreshData();
                refreshNavBadge();
            }).fail(function (xhr) {
                showToast(xhr.responseJSON?.message || t('generic_failed', 'Failed'), 'error');
            });
        });
    });

    function initTable() {
        table = $('#pengajuanTable').DataTable({
            dom: 'rt<"dt-bottom"lip>',
            ordering: false,
            pageLength: 25,
            columns: [
                { data: 'no_index' },
                { data: 'marking' },
                { data: 'cbm' },
                { data: 'kg' },
                { data: 'mode' },
                { data: 'proposed' },
                { data: 'status' },
                { data: 'submitted' },
                { data: 'action', orderable: false, className: 'text-nowrap' },
            ],
            language: {
                search: '',
                searchPlaceholder: 'Cari...',
                sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data',
                infoEmpty: t('no_data', 'Tidak ada data'),
                emptyTable: t('no_data', 'Tidak ada data'),
                paginate: {
                    next: '<i class="bi bi-chevron-right"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                },
            },
        });
    }

    function refreshData() {
        $.get('/finance/pengajuan/data', { status: $('#filterStatus').val() }, function (rows) {
            table.clear().draw();
            table.rows.add(rows.map(formatRow)).draw();
            refreshNavBadge();
        }).fail(function () {
            showToast(t('failed_load_data', 'Gagal memuat data'), 'error');
        });
    }

    window.refreshPengajuanNavBadge = refreshNavBadge;

    function refreshNavBadge() {
        $.get('/finance/pengajuan/badge', function (res) {
            var n = parseInt(res.pending, 10) || 0;
            var label = n > 99 ? '99+' : String(n);
            $('#navPengajuanBadge, #tabPengajuanBadge').each(function () {
                var $badge = $(this);
                if (n > 0) {
                    $badge.text(label).removeClass('d-none').attr('title', n + ' pengajuan menunggu');
                } else {
                    $badge.addClass('d-none');
                }
            });
        });
    }

    function statusBadge(status) {
        if (status === 'approved') {
            return '<span class="badge-glass badge-status-approved">' + escHtml(t('pengajuan_status_approved', 'Disetujui')) + '</span>';
        }
        if (status === 'rejected') {
            return '<span class="badge-glass badge-status-rejected">' + escHtml(t('pengajuan_status_rejected', 'Ditolak')) + '</span>';
        }
        return '<span class="badge-glass badge-status-pending">' + escHtml(t('repricing_pending', 'Menunggu')) + '</span>';
    }

    function formatRow(row) {
        var isWeight = row.pricing_mode === 'weight';
        var proposed = isWeight
            ? fmtNumber(row.new_weight_price, 0) + ' /kg'
            : fmtNumber(row.new_price_per_cbm, 0) + ' /CBM';
        if (parseFloat(row.new_extra_charge) > 0) {
            proposed += '<div class="text-muted-glass small">+ Extra ' + fmtNumber(row.new_extra_charge, 0) + '</div>';
        }

        var modeHtml = isWeight
            ? '<span class="badge-glass">' + escHtml(t('repricing_mode_weight', 'Berat (KG)')) + '</span>'
            : '<span class="badge-glass">' + escHtml(t('repricing_mode_cbm', 'CBM')) + '</span>';

        var threshold = parseFloat(row.weight_threshold_kg) || 0;
        var cbmHtml = '<span class="text-accent fw-700">' + (window.fmtCbm ? window.fmtCbm(row.total_cbm || 0) : Number(row.total_cbm || 0).toFixed(3)) + '</span>';
        if (threshold > 0) {
            cbmHtml += '<div class="text-muted-glass" style="font-size:.65rem">Th: ' + threshold.toFixed(2) + '</div>';
        }

        var action = '';
        if (row.proof_url) {
            action += '<a href="' + escAttr(row.proof_url) + '" target="_blank" rel="noopener" class="btn btn-sm btn-glass mb-1"><i class="bi bi-image"></i></a> ';
        }
        if (row.status === 'pending' && window._pengajuanCanApprove) {
            action += '<div class="d-flex gap-1">' +
                '<button type="button" class="btn btn-sm btn-accent btn-pengajuan-approve" data-request-id="' + row.id + '" data-index="' + escAttr(row.no_index || '') + '"><i class="bi bi-check-lg"></i></button>' +
                '<button type="button" class="btn btn-sm btn-glass btn-pengajuan-reject" data-request-id="' + row.id + '" data-index="' + escAttr(row.no_index || '') + '"><i class="bi bi-x-lg"></i></button>' +
                '</div>';
        }
        if (row.status === 'rejected' && row.rejection_note) {
            action += '<div class="small text-danger mt-1">' + escHtml(row.rejection_note) + '</div>';
        }

        return {
            no_index: '<span class="fw-600 text-white">' + escHtml(row.no_index || '-') + '</span>',
            marking: escHtml(row.marking || '-'),
            cbm: cbmHtml,
            kg: '<span class="fw-600">' + Number(row.total_gross_weight || 0).toFixed(2) + '</span>',
            mode: modeHtml,
            proposed: '<span class="text-warning fw-600">' + proposed + '</span>',
            status: statusBadge(row.status),
            submitted: '<div class="small text-white">' + escHtml(row.submitted_by || '-') + '</div>' +
                '<div class="text-muted-glass" style="font-size:.65rem">' + escHtml(formatDateTime(row.submitted_at)) + '</div>',
            action: action || '-',
        };
    }

    function escHtml(value) {
        return $('<span>').text(value == null ? '' : value).html();
    }

    function escAttr(value) {
        return escHtml(value).replace(/"/g, '&quot;');
    }

    function formatDateTime(value) {
        if (!value) {
            return '-';
        }
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
});
