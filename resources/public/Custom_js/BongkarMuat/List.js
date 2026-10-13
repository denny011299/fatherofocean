document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.__bongkarMuatListConfig || {};
    var allData = [];

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function token() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function tpl(url, vars) {
        var out = url;
        Object.keys(vars).forEach(function (k) {
            out = out.replace('__' + k + '__', String(vars[k]));
        });
        return out;
    }

    var table = $('#bongkarMuatTable').DataTable({
        paging: true,
        searching: false,
        ordering: false,
        info: true,
        lengthChange: false,
        pageLength: 25,
        columns: [
            { data: 'partai' },
            { data: 'container_no' },
            { data: 'status_html' },
            { data: 'scan_count' },
            { data: 'by_label' },
            { data: 'date_label' },
            { data: 'action', orderable: false },
        ],
    });

    function loadData() {
        $.get(cfg.dataUrl, function (rows) {
            allData = (rows || []).map(formatRow);
            applyFilters();
        }).fail(function () {
            if (typeof window.showToast === 'function') {
                window.showToast(t('load_failed'), 'error');
            }
        });
    }

    function formatRow(row) {
        var statusClass = row.status === 'completed' ? 'success' : 'warning';
        var action = '—';
        if (cfg.canManage) {
            var parts = [];
            if (row.can_continue) {
                parts.push(
                    '<a href="' + esc(cfg.createUrl) + '?session=' + row.id + '" class="btn btn-sm btn-accent bm-action-btn"'
                    + ' title="' + esc(t('btn_continue', 'Lanjutkan')) + '">'
                    + '<i class="bi bi-play-fill"></i></a>',
                );
            }
            if (row.can_delete) {
                parts.push(
                    '<button type="button" class="btn btn-sm btn-glass text-danger bm-action-btn bm-session-delete"'
                    + ' data-id="' + row.id + '" title="' + esc(t('session_delete', 'Hapus sesi')) + '">'
                    + '<i class="bi bi-trash3"></i></button>',
                );
            } else if (row.status === 'completed') {
                parts.push(
                    '<button type="button" class="btn btn-sm btn-glass text-muted bm-action-btn"'
                    + ' title="' + esc(t('completed_not_deletable', 'Sesi selesai tidak bisa dihapus')) + '" disabled>'
                    + '<i class="bi bi-trash3"></i></button>',
                );
            }
            action = parts.length ? '<div class="bm-actions">' + parts.join('') + '</div>' : '—';
        } else if (row.status === 'completed') {
            action = '<span class="small text-muted-glass">' + esc(t('status_completed', 'Selesai')) + '</span>';
        }

        return {
            partai: '<span class="fw-700 text-accent">' + esc(row.partai) + '</span>',
            container_no: esc(row.container_no),
            status_html: '<span class="badge bg-' + statusClass + '">' + esc(row.status_label) + '</span>',
            scan_count: String(row.scan_count || 0),
            by_label: esc(row.status === 'completed' ? row.completed_by : row.started_by),
            date_label: esc(row.status === 'completed' ? row.completed_at : row.updated_at),
            action: action,
            _status: row.status,
            _search: [row.partai, row.container_no, row.container_label, row.started_by, row.completed_by].join(' ').toLowerCase(),
        };
    }

    function applyFilters() {
        var search = ($('#filterSearch').val() || '').toLowerCase().trim();
        var status = $('#filterStatus').val();
        var filtered = allData.filter(function (row) {
            if (status && row._status !== status) {
                return false;
            }
            if (search && row._search.indexOf(search) === -1) {
                return false;
            }
            return true;
        });
        table.clear().rows.add(filtered).draw();
    }

    $('#filterSearch').on('input', debounce(applyFilters, 200));
    $('#filterStatus').on('change', applyFilters);
    $('#btnResetFilter').on('click', function () {
        $('#filterSearch').val('');
        $('#filterStatus').val('');
        applyFilters();
    });

    $(document).on('click', '.bm-session-delete', function () {
        var id = $(this).data('id');
        if (!id || !cfg.deleteTpl) {
            return;
        }

        window.confirmDelete(t('session_delete_confirm', 'Hapus sesi bongkar muat ini?'), function () {
            $.ajax({
                url: tpl(cfg.deleteTpl, { SID: id }),
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': token() },
                success: function (res) {
                    if (typeof window.showToast === 'function') {
                        window.showToast(res.message || t('success'));
                    }
                    loadData();
                },
                error: function (xhr) {
                    if (typeof window.showToast === 'function') {
                        window.showToast((xhr.responseJSON && xhr.responseJSON.message) || t('generic_failed'), 'error');
                    }
                },
            });
        });
    });

    loadData();

    function debounce(fn, ms) {
        var timer;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(fn, ms);
        };
    }
});
