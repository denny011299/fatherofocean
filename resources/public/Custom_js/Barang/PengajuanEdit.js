(function () {
    'use strict';

    function whenReady(callback) {
        if (typeof window.jQuery === 'function' && typeof window.jQuery.fn.dataTable === 'function') {
            callback();
            return;
        }

        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;
            if (typeof window.jQuery === 'function' && typeof window.jQuery.fn.dataTable === 'function') {
                window.clearInterval(timer);
                callback();
                return;
            }
            if (attempts >= 50) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    function apiUrl(key, id) {
        var urls = window._barangEditPengajuanUrls || {};
        var template = urls[key] || '';
        if (!template) {
            return '';
        }
        return id == null ? template : template.replace('__ID__', String(id));
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('barangEditPengajuanTable')) {
            return;
        }

        whenReady(initPengajuanEditTab);
    });

    function initPengajuanEditTab() {
        var $ = window.jQuery;

        function t(key, fallback) {
            return (window.__t && window.__t[key]) || fallback || key;
        }

        var table;
        var modalEl = document.getElementById('barangEditRequestModal');
        var modal = modalEl && typeof bootstrap !== 'undefined' ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

        initTable();
        refreshData();
        refreshBadge();

        window.barangPengajuanEditOnTabShow = function () {
            refreshData();
            window.setTimeout(function () {
                if (table) {
                    table.columns.adjust().draw(false);
                }
            }, 50);
        };

        $('#filterEditPengajuanStatus').on('change', refreshData);
        $('#btnRefreshEditPengajuan').on('click', refreshData);

        $(document).on('click', '.btn-barang-edit-view', function () {
            openViewModal($(this).data('request-id'));
        });

        $(document).on('click', '.btn-barang-edit-approve', function () {
            var id = $(this).data('request-id');
            var idx = $(this).data('index');
            Swal.fire({
                title: t('edit_pengajuan_confirm_approve_title', 'Setujui perubahan?'),
                text: (t('edit_pengajuan_confirm_approve_text', 'Terapkan perubahan untuk :index?') || '').replace(':index', idx),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: t('approve', 'Setujui'),
                cancelButtonText: t('cancel', 'Batal'),
                confirmButtonColor: 'var(--accent, #818cf8)',
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                $.post(apiUrl('approve', id), function (res) {
                    showToast(res.message || t('edit_request_approved', 'Perubahan disetujui'));
                    refreshData();
                    refreshBadge();
                }).fail(function (xhr) {
                    showToast(xhr.responseJSON?.message || t('generic_failed', 'Gagal'), 'error');
                });
            });
        });

        $(document).on('click', '.btn-barang-edit-reject', function () {
            var id = $(this).data('request-id');
            var idx = $(this).data('index');
            Swal.fire({
                title: t('edit_pengajuan_confirm_reject_title', 'Tolak perubahan?'),
                input: 'textarea',
                inputLabel: t('rejection_note_label', 'Alasan penolakan'),
                inputPlaceholder: t('rejection_note_placeholder', 'Catatan penolakan...'),
                inputAttributes: { maxlength: 500 },
                showCancelButton: true,
                confirmButtonText: t('reject', 'Tolak'),
                cancelButtonText: t('cancel', 'Batal'),
                confirmButtonColor: '#fb7185',
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return t('reject_reason_required', 'Alasan penolakan wajib diisi');
                    }
                },
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                $.post(apiUrl('reject', id), { rejection_note: result.value }, function (res) {
                    showToast(res.message || t('edit_request_rejected', 'Perubahan ditolak'));
                    refreshData();
                    refreshBadge();
                }).fail(function (xhr) {
                    showToast(xhr.responseJSON?.message || t('generic_failed', 'Gagal'), 'error');
                });
            });
        });

        function initTable() {
            table = $('#barangEditPengajuanTable').DataTable({
                dom: 'rt<"dt-bottom"lip>',
                ordering: false,
                pageLength: 25,
                columns: [
                    { data: 'no_index' },
                    { data: 'marking' },
                    { data: 'cbm' },
                    { data: 'kg' },
                    { data: 'changes' },
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
            if (!table) {
                return;
            }

            var dataUrl = apiUrl('data');
            if (!dataUrl) {
                showToast(t('failed_load_data', 'Gagal memuat data'), 'error');
                return;
            }

            $.get(dataUrl, { status: $('#filterEditPengajuanStatus').val() }, function (rows) {
                if (!Array.isArray(rows)) {
                    showToast(t('failed_load_data', 'Gagal memuat data'), 'error');
                    return;
                }
                table.clear().draw();
                table.rows.add(rows.map(formatRow)).draw();
                toggleEmptyHints(rows.length === 0);
                refreshBadge();
                window.setTimeout(function () {
                    table.columns.adjust().draw(false);
                }, 50);
            }).fail(function (xhr) {
                var msg = xhr.responseJSON?.message || t('failed_load_data', 'Gagal memuat data');
                showToast(msg + (xhr.status ? ' (' + xhr.status + ')' : ''), 'error');
            });
        }

        function toggleEmptyHints(isEmpty) {
            var $approverHint = $('#editPengajuanApproverHint');
            var $submitterHint = $('#editPengajuanSubmitterEmptyHint');
            if ($approverHint.length) {
                $approverHint.toggleClass('d-none', !isEmpty || !window._barangEditPengajuanCanApprove);
            }
            if ($submitterHint.length) {
                $submitterHint.toggleClass('d-none', !isEmpty || window._barangEditPengajuanCanApprove);
            }
        }

        function refreshBadge() {
            var badgeUrl = apiUrl('badge');
            if (!badgeUrl) {
                return;
            }

            $.get(badgeUrl, function (res) {
                var n = parseInt(res.pending, 10) || 0;
                var label = n > 99 ? '99+' : String(n);
                $('#tabBarangEditPengajuanBadge').each(function () {
                    var $badge = $(this);
                    if (n > 0) {
                        $badge.text(label).removeClass('d-none');
                    } else {
                        $badge.addClass('d-none');
                    }
                });
            }).fail(function () {
                /* keep server-rendered badge count on transient errors */
            });
        }

        function openViewModal(id) {
            var showUrl = apiUrl('show', id);
            if (!showUrl) {
                return;
            }

            $.get(showUrl, function (data) {
                renderEditRequestModal(data);

                if (modal) {
                    modal.show();
                }
            }).fail(function (xhr) {
                showToast(xhr.responseJSON?.message || t('failed_load_data', 'Gagal memuat data'), 'error');
            });
        }

        function renderEditRequestModal(data) {
            var diff = Array.isArray(data.diff) ? data.diff : [];
            var grouped = groupDiffRows(diff);

            $('#barangEditRequestSummary').html(buildEditRequestSummary(data, diff.length));
            $('#barangEditRequestDiffPanel').html(buildEditRequestDiffPanel(grouped));

            if (!diff.length) {
                $('#barangEditRequestDiffPanel').addClass('d-none');
                $('#barangEditRequestEmpty').removeClass('d-none');
                return;
            }

            $('#barangEditRequestDiffPanel').removeClass('d-none');
            $('#barangEditRequestEmpty').addClass('d-none');
        }

        function groupDiffRows(diff) {
            var productGroups = [];
            var productMap = {};
            var barangRows = [];

            diff.forEach(function (row) {
                if (row.group === 'product') {
                    var parsed = parseProductDiffRow(row);
                    if (!parsed) {
                        return;
                    }

                    if (!productMap[parsed.context]) {
                        productMap[parsed.context] = {
                            context: parsed.context,
                            type: parsed.type,
                            changes: [],
                        };
                        productGroups.push(productMap[parsed.context]);
                    }

                    var group = productMap[parsed.context];
                    if (parsed.type === 'new' || parsed.type === 'removed') {
                        group.type = parsed.type;
                    }

                    group.changes.push({
                        field: parsed.field,
                        old: row.old,
                        new: row.new,
                        type: parsed.type,
                        metricKey: parsed.metricKey,
                    });
                    return;
                }

                barangRows.push(row);
            });

            return {
                productGroups: productGroups,
                barangRows: barangRows,
            };
        }

        function parseProductDiffRow(row) {
            var fieldKey = String(row.field || '');
            var label = String(row.label || row.field || '');
            var type = 'modified';

            if (fieldKey.indexOf('product.new.') === 0) {
                type = 'new';
            } else if (fieldKey.indexOf('product.removed.') === 0) {
                type = 'removed';
            }

            var parts = label.split(' · ');
            var context = parts[0] || label;
            var fieldLabel = parts.slice(1).join(' · ');

            if (type === 'new' || type === 'removed') {
                fieldLabel = fieldLabel || (type === 'new'
                    ? t('edit_pengajuan_summary_new', 'Item baru')
                    : t('edit_pengajuan_summary_removed', 'Item dihapus'));
            }

            if (type === 'modified' && !fieldLabel) {
                fieldLabel = label;
            }

            var metricKey = '';
            var metricMatch = fieldKey.match(/^product\.\d+\.([a-z_]+)$/);
            if (metricMatch) {
                metricKey = metricMatch[1];
            }

            if (type !== 'modified' && String(row.old || '-') === '-' && String(row.new || '-') === '-') {
                if (!context || context === '-') {
                    return null;
                }
            }

            return {
                context: context,
                field: fieldLabel,
                type: type,
                metricKey: metricKey,
            };
        }

        function buildEditRequestSummary(data, diffCount) {
            var status = statusBadge(data.status || 'pending');
            var changesLabel = (t('edit_pengajuan_summary_changes', ':count perubahan') || ':count perubahan')
                .replace(':count', String(diffCount));

            return '' +
                '<div class="barang-edit-diff-summary-grid">' +
                    '<div class="barang-edit-diff-summary-item">' +
                        '<div class="barang-edit-diff-summary-label">' + escHtml(t('label_no_index', 'NO URUT')) + '</div>' +
                        '<div class="barang-edit-diff-summary-value">' + escHtml(data.no_index || '-') + '</div>' +
                    '</div>' +
                    '<div class="barang-edit-diff-summary-item">' +
                        '<div class="barang-edit-diff-summary-label">' + escHtml(t('col_marking', 'Marking')) + '</div>' +
                        '<div class="barang-edit-diff-summary-value">' + escHtml(data.marking || '-') + '</div>' +
                    '</div>' +
                    '<div class="barang-edit-diff-summary-item">' +
                        '<div class="barang-edit-diff-summary-label">' + escHtml(t('edit_pengajuan_col_submitted', 'Diajukan')) + '</div>' +
                        '<div class="barang-edit-diff-summary-value">' + escHtml(data.submitted_by || '-') + '</div>' +
                        '<div class="barang-edit-diff-summary-meta">' + escHtml(formatDateTime(data.submitted_at)) + '</div>' +
                    '</div>' +
                    '<div class="barang-edit-diff-summary-item">' +
                        '<div class="barang-edit-diff-summary-label">' + escHtml(t('col_status', 'Status')) + '</div>' +
                        '<div class="barang-edit-diff-summary-badges">' + status + '<span class="badge-glass">' + escHtml(changesLabel) + '</span></div>' +
                    '</div>' +
                '</div>';
        }

        function buildEditRequestDiffPanel(grouped) {
            var html = '';
            var modifiedCount = 0;
            var addedCount = 0;
            var removedCount = 0;

            grouped.productGroups.forEach(function (group) {
                if (group.type === 'new') {
                    addedCount += 1;
                } else if (group.type === 'removed') {
                    removedCount += 1;
                } else {
                    modifiedCount += 1;
                }
            });

            if (grouped.productGroups.length) {
                var countsLabel = (t('edit_pengajuan_diff_counts', ':modified diubah · :added baru · :removed dihapus') || '')
                    .replace(':modified', String(modifiedCount))
                    .replace(':added', String(addedCount))
                    .replace(':removed', String(removedCount));

                html += '<section class="barang-edit-diff-section">' +
                    '<div class="barang-edit-diff-section-head">' +
                        '<i class="bi bi-box-seam" aria-hidden="true"></i>' +
                        '<div>' +
                            '<div class="barang-edit-diff-section-title">' + escHtml(t('edit_pengajuan_section_products', 'Daftar Produk')) + '</div>' +
                            '<div class="barang-edit-diff-section-subtitle">' + escHtml(t('edit_pengajuan_section_products_hint', 'Perbandingan berat & CBM per box')) + '</div>' +
                            '<div class="barang-edit-diff-section-counts">' + escHtml(countsLabel) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="barang-edit-diff-product-grid">' +
                        grouped.productGroups.map(renderProductDiffCard).join('') +
                    '</div>' +
                '</section>';
            }

            if (grouped.barangRows.length) {
                html += '<section class="barang-edit-diff-section">' +
                    '<div class="barang-edit-diff-section-head">' +
                        '<i class="bi bi-file-earmark-text" aria-hidden="true"></i>' +
                        '<div>' +
                            '<div class="barang-edit-diff-section-title">' + escHtml(t('edit_pengajuan_section_header', 'Data Barang')) + '</div>' +
                            '<div class="barang-edit-diff-section-subtitle">' + escHtml(t('edit_pengajuan_section_header_hint', 'Perubahan data header pengiriman')) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="barang-edit-diff-barang-list">' +
                        grouped.barangRows.map(renderBarangDiffRow).join('') +
                    '</div>' +
                '</section>';
            }

            return html;
        }

        function renderProductDiffCard(group) {
            var typeClass = 'is-' + (group.type || 'modified');
            var typeLabel = productChangeTypeLabel(group.type);
            var changesHtml = '';

            if (group.type === 'new' || group.type === 'removed') {
                var value = group.type === 'new'
                    ? (group.changes[0] && group.changes[0].new) || '-'
                    : (group.changes[0] && group.changes[0].old) || '-';
                var detailLines = String(value).split(' · ').filter(function (line) {
                    return !!line;
                });
                if (detailLines.length > 1) {
                    changesHtml = '<div class="barang-edit-diff-new-item-detail">' +
                        detailLines.map(function (line) {
                            return '<div class="barang-edit-diff-new-item-line">' + escHtml(line) + '</div>';
                        }).join('') +
                    '</div>';
                } else {
                    changesHtml = '<div class="barang-edit-diff-single-value">' + escHtml(value) + '</div>';
                }
            } else if (isParentMetricDiff(group)) {
                changesHtml = '<div class="barang-edit-diff-metrics">' +
                    group.changes.map(renderMetricDiffRow).join('') +
                '</div>';
            } else {
                changesHtml = '<div class="barang-edit-diff-change-list">' +
                    group.changes.map(renderFieldDiffRow).join('') +
                '</div>';
            }

            return '' +
                '<article class="barang-edit-diff-card ' + typeClass + (isParentMetricDiff(group) ? ' is-metrics' : '') + '">' +
                    '<div class="barang-edit-diff-card-head">' +
                        '<div class="barang-edit-diff-card-title">' + escHtml(group.context) + '</div>' +
                        '<span class="barang-edit-diff-type-badge">' + escHtml(typeLabel) + '</span>' +
                    '</div>' +
                    changesHtml +
                '</article>';
        }

        function isParentMetricDiff(group) {
            if (group.type !== 'modified' || !group.changes.length) {
                return false;
            }

            var context = String(group.context || '').toLowerCase();
            if (context.indexOf('sub-item') >= 0 || context.indexOf('sub item') >= 0 || context.indexOf('子项') >= 0) {
                return false;
            }

            return group.changes.every(function (change) {
                return change.metricKey === 'gross_weight_kg' || change.metricKey === 'volume_cbm';
            });
        }

        function renderMetricDiffRow(change) {
            var label = metricLabel(change.metricKey, change.field);

            return '' +
                '<div class="barang-edit-diff-metric-row">' +
                    '<div class="barang-edit-diff-metric-label">' + escHtml(label) + '</div>' +
                    '<div class="barang-edit-diff-metric-values">' +
                        '<span class="barang-edit-diff-metric-old">' + escHtml(change.old) + '</span>' +
                        '<i class="bi bi-arrow-right-short barang-edit-diff-metric-arrow" aria-hidden="true"></i>' +
                        '<span class="barang-edit-diff-metric-new">' + escHtml(change.new) + '</span>' +
                    '</div>' +
                '</div>';
        }

        function metricLabel(metricKey, fallback) {
            if (metricKey === 'gross_weight_kg') {
                return t('edit_pengajuan_metric_gw', 'GW (kg)');
            }
            if (metricKey === 'volume_cbm') {
                return t('edit_pengajuan_metric_cbm', 'CBM (m³)');
            }

            return fallback || metricKey;
        }

        function renderBarangDiffRow(row) {
            return '' +
                '<div class="barang-edit-diff-barang-row">' +
                    '<div class="barang-edit-diff-field">' + escHtml(row.label || row.field) + '</div>' +
                    '<div class="barang-edit-diff-values">' +
                        '<span class="barang-edit-diff-old">' + escHtml(row.old) + '</span>' +
                        '<i class="bi bi-arrow-right-short barang-edit-diff-arrow" aria-hidden="true"></i>' +
                        '<span class="barang-edit-diff-new">' + escHtml(row.new) + '</span>' +
                    '</div>' +
                '</div>';
        }

        function renderFieldDiffRow(change) {
            return '' +
                '<div class="barang-edit-diff-change">' +
                    '<div class="barang-edit-diff-field">' + escHtml(change.field) + '</div>' +
                    '<div class="barang-edit-diff-values">' +
                        '<span class="barang-edit-diff-old">' + escHtml(change.old) + '</span>' +
                        '<i class="bi bi-arrow-right-short barang-edit-diff-arrow" aria-hidden="true"></i>' +
                        '<span class="barang-edit-diff-new">' + escHtml(change.new) + '</span>' +
                    '</div>' +
                '</div>';
        }

        function productChangeTypeLabel(type) {
            if (type === 'new') {
                return t('edit_pengajuan_change_type_new', 'Baru');
            }
            if (type === 'removed') {
                return t('edit_pengajuan_change_type_removed', 'Dihapus');
            }
            return t('edit_pengajuan_change_type_modified', 'Diubah');
        }

        function statusBadge(status) {
            if (status === 'approved') {
                return '<span class="badge-glass badge-status-approved">' + escHtml(t('approval_approved', 'Disetujui')) + '</span>';
            }
            if (status === 'rejected') {
                return '<span class="badge-glass badge-status-rejected">' + escHtml(t('approval_rejected', 'Ditolak')) + '</span>';
            }
            return '<span class="badge-glass badge-status-pending">' + escHtml(t('approval_pending', 'Menunggu')) + '</span>';
        }

        function actionTitle(key, fallback) {
            return escAttr(t(key, fallback));
        }

        function formatRow(row) {
            var actionBtns = '<button type="button" class="btn btn-glass btn-sm btn-barang-edit-view" data-request-id="' + row.id + '"' +
                ' title="' + actionTitle('view', 'Lihat') + '">' +
                '<i class="bi bi-eye"></i></button>';

            if (row.status === 'pending' && window._barangEditPengajuanCanApprove) {
                actionBtns += '<button type="button" class="btn btn-accent btn-sm btn-barang-edit-approve" data-request-id="' + row.id + '"' +
                    ' data-index="' + escAttr(row.no_index || '') + '"' +
                    ' title="' + actionTitle('approve', 'Setujui') + '">' +
                    '<i class="bi bi-check-lg"></i></button>';
                if (window._barangEditPengajuanCanReject) {
                    actionBtns += '<button type="button" class="btn btn-glass btn-sm text-danger btn-barang-edit-reject" data-request-id="' + row.id + '"' +
                        ' data-index="' + escAttr(row.no_index || '') + '"' +
                        ' title="' + actionTitle('reject', 'Tolak') + '">' +
                        '<i class="bi bi-x-lg"></i></button>';
                }
            }

            var action = '<div class="barang-edit-pengajuan-actions">' + actionBtns + '</div>';

            if (row.status === 'rejected' && row.rejection_note) {
                action += '<div class="small text-danger mt-1 barang-edit-pengajuan-note">' + escHtml(row.rejection_note) + '</div>';
            }

            return {
                no_index: '<span class="fw-600 text-white">' + escHtml(row.no_index || '-') + '</span>',
                marking: escHtml(row.marking || '-'),
                cbm: '<span class="text-accent fw-700">' + (window.fmtCbm ? window.fmtCbm(row.total_cbm || 0) : Number(row.total_cbm || 0).toFixed(3)) + '</span>',
                kg: '<span class="fw-600">' + Number(row.total_gross_weight || 0).toFixed(2) + '</span>',
                changes: '<span class="badge-glass">' + Number(row.diff_count || 0) + ' ' + escHtml(t('edit_pengajuan_changes', 'perubahan')) + '</span>',
                status: statusBadge(row.status),
                submitted: '<div class="small text-white">' + escHtml(row.submitted_by || '-') + '</div>' +
                    '<div class="text-muted-glass" style="font-size:.65rem">' + escHtml(formatDateTime(row.submitted_at)) + '</div>',
                action: action,
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

        var flash = null;
        try {
            flash = JSON.parse(sessionStorage.getItem('barangFormFlash') || 'null');
            if (!flash || !flash.message) {
                flash = JSON.parse(sessionStorage.getItem('barangEditPengajuanFlash') || 'null');
                if (flash && flash.message) {
                    sessionStorage.removeItem('barangEditPengajuanFlash');
                }
            } else {
                sessionStorage.removeItem('barangFormFlash');
            }
        } catch (e) {
            flash = null;
        }
        if (flash && flash.message) {
            showToast(flash.message, flash.type || 'success');
        }

        if (document.querySelector('#tabPengajuanEdit.show.active')) {
            window.barangPengajuanEditOnTabShow();
        }
    }
})();
