document.addEventListener('DOMContentLoaded', function () {
    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function escAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function tipAttrs(key, fallback) {
        var tip = escAttr(t(key, fallback));
        return ' data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="' + tip + '" title="' + tip + '"';
    }

    function ajaxErrorMessage(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        if (json && json.errors) {
            return Object.values(json.errors).flat().join('\n');
        }

        return json && json.message ? json.message : fallback;
    }

    function normalizeSubordinateIds(raw) {
        if (!raw) {
            return [];
        }
        var list = Array.isArray(raw) ? raw : [raw];

        return list
            .map(function (id) {
                var parsed = parseInt(String(id).trim(), 10);
                return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
            })
            .filter(function (id) {
                return id > 0;
            });
    }

    var mode = 1;
    var table;
    var subordinatesUserId = null;
    var subordinatesParentName = '';
    var canManageUser = typeof _canManageUser !== 'undefined' && _canManageUser;
    var subordinatesTableReady = typeof _subordinatesTableReady !== 'undefined' && _subordinatesTableReady;

    inisialisasi();
    refreshData();

    $('#selRole').on('change select2:select', toggleCommissionRateField);

    $(document).on('click', '.btnAdd', function () {
        mode = 1;
        $('#formModalTitle').html('<i class="bi bi-person-plus me-2 text-accent"></i>' + t('add', 'Add User'));
        $('#dataForm')[0].reset();
        $('[name="password"]').attr('required', 'required');
        $('#passwordLabel').html('Password <span class="text-danger">*</span>');
        $('#selRole').val('');
        toggleCommissionRateField();
        $('.is-invalid').removeClass('is-invalid');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $('#dataForm').on('submit', function (e) {
        e.preventDefault();
        simpanData();
    });

    $(document).on('click', '.btn_subordinates', function (e) {
        e.preventDefault();
        openSubordinatesManage(rowDataFromEl($(this)));
    });

    $('#btnManageFromView').on('click', function () {
        if (!subordinatesUserId) {
            return;
        }
        var name = subordinatesParentName;
        bootstrap.Modal.getInstance(document.getElementById('subordinatesViewModal'))?.hide();
        openSubordinatesManage({ id: subordinatesUserId, raw_name: name, name: name });
    });

    $('#btnClearSubordinatesFromView, #btnClearSubordinatesInModal').on('click', function () {
        if (!subordinatesUserId) {
            return;
        }
        confirmClearSubordinates(subordinatesUserId, subordinatesParentName);
    });

    $('#subordinatesModal').on('shown.bs.modal', function () {
        if (subordinatesUserId) {
            loadSubordinates(subordinatesUserId);
        }
    });

    $('#subordinatesModal').on('hidden.bs.modal', function () {
        destroySubordinatesSelect();
        subordinatesUserId = null;
        $('#selSubordinates').empty().append('<option value=""></option>');
        updateSubordinatesBadge(0);
    });

    $('#subordinatesForm').on('submit', function (e) {
        e.preventDefault();
        if (!subordinatesUserId) {
            return;
        }
        var btn = document.getElementById('btnSaveSubordinates');
        var spinner = document.getElementById('subordinatesSpinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');
        $.ajax({
            url: '/user/' + subordinatesUserId + '/subordinates',
            method: 'post',
            data: {
                _token: token,
                subordinate_ids: normalizeSubordinateIds($('#selSubordinates').val()),
            },
            success: function (res) {
                bootstrap.Modal.getInstance(document.getElementById('subordinatesModal')).hide();
                notifikasi('success', t('success_update', 'Updated'), res.message);
                if (subordinatesUserId && res.subordinate_count != null) {
                    var row = table.rows().nodes().to$().filter(function () {
                        return table.row(this).data() && table.row(this).data().id === subordinatesUserId;
                    });
                    if (row.length) {
                        var rowData = table.row(row[0]).data();
                        rowData.subordinate_count = res.subordinate_count;
                        table.row(row[0]).data(rowData).invalidate();
                    }
                }
                refreshData();
            },
            error: function (xhr) {
                notifikasi('error', t('generic_failed', 'Failed'), ajaxErrorMessage(xhr, t('save_failed', 'Failed to save.')));
            },
            complete: function () {
                btn.disabled = false;
                spinner.classList.add('d-none');
            },
        });
    });

    $(document).on('click', '.btn_edit', function () {
        var data = table.row($(this).parents('tr')).data();
        mode = 2;
        $('#formModalTitle').html('<i class="bi bi-person-gear me-2 text-accent"></i>Edit User');
        $('.is-invalid').removeClass('is-invalid');
        $('[name="name"]').val(data.raw_name);
        $('[name="username"]').val(data.raw_username);
        $('[name="email"]').val(data.raw_email || '');
        $('[name="phone"]').val(data.raw_phone || '');
        $('[name="password"]').val('').removeAttr('required');
        $('#passwordLabel').html('Password <small class="text-muted">(kosongkan jika tidak diubah)</small>');
        $('#selRole').val(data.role).trigger('change');
        $('#commissionRateOverride').val(
            data.commission_rate_override != null && data.commission_rate_override !== ''
                ? data.commission_rate_override
                : ''
        );
        toggleCommissionRateField();
        $('#formModal').attr('data-edit-id', data.id);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $(document).on('click', '.btn_delete', function () {
        var data = table.row($(this).parents('tr')).data();
        Swal.fire({
            title: t('delete_user_title', 'Delete user?'), text: (t('delete_user_text', 'User ":name" will be permanently deleted.')).replace(':name', data.raw_name),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280',
            confirmButtonText: t('confirm_delete', 'Yes, Delete'), cancelButtonText: t('cancel', 'Cancel')
        }).then(function (result) {
            if (result.isConfirmed) hapusData(data.id);
        });
    });

    function rowDataFromEl($el) {
        return table.row($el.closest('tr')).data();
    }

    function inisialisasi() {
        var cols = [
            { data: 'name' }, { data: 'username' }, { data: 'email' }, { data: 'phone' },
            { data: 'role_label' },
            { data: 'commission_label', className: 'text-center' },
        ];
        cols.push({ data: 'created_at' }, { data: 'action', className: 'text-center' });

        table = $('#userTable').DataTable({
            dom: '<"dt-top"f>rt<"dt-bottom"lip>', ordering: true, pageLength: 25,
            columns: cols,
            language: { search: '', searchPlaceholder: t('search', 'Search') + '...', sLengthMenu: t('dt_per_page', '_MENU_ per page'),
                info: t('dt_info', '_START_ - _END_ of _TOTAL_'), infoEmpty: t('dt_info_empty', 'No entries'), emptyTable: t('dt_empty_table', 'No data available'),
                paginate: { next: '&raquo;', previous: '&laquo;' } }
        });
        table.on('draw.dt', function () {
            if (window.initBsTooltips) {
                window.initBsTooltips('#userTable');
            }
        });
    }

    function refreshData() {
        $.ajax({
            url: '/user/data', method: 'get',
            success: function (e) {
                table.clear().draw();
                for (var i = 0; i < e.length; i++) {
                    e[i].raw_name = e[i].raw_name || e[i].name;
                    e[i].raw_username = e[i].raw_username || e[i].username;
                    e[i].raw_email = e[i].email || '';
                    e[i].raw_phone = e[i].phone || '';
                    e[i].name = '<span class="fw-600" style="color:var(--text-primary)">' + e[i].raw_name + '</span>';
                    e[i].username = '<code>' + e[i].raw_username + '</code>';
                    e[i].email = '<span class="small">' + (e[i].raw_email || '-') + '</span>';
                    e[i].phone = '<span class="small">' + (e[i].raw_phone || '-') + '</span>';
                    var roleColors = {
                        'super_admin': 'rgba(129,140,248,.15);color:#818cf8;border-color:rgba(129,140,248,.3)',
                        'supervisor': 'rgba(168,85,247,.15);color:#a855f7;border-color:rgba(168,85,247,.3)',
                        'admin_cs': 'rgba(59,130,246,.15);color:#3b82f6;border-color:rgba(59,130,246,.3)',
                        'sales': 'rgba(52,211,153,.15);color:#34d399;border-color:rgba(52,211,153,.3)',
                        'finance': 'rgba(251,191,36,.15);color:#fbbf24;border-color:rgba(251,191,36,.3)',
                        'admin_gudang_china': 'rgba(244,63,94,.15);color:#f43f5e;border-color:rgba(244,63,94,.3)',
                        'admin_gudang_indo': 'rgba(56,189,248,.15);color:#38bdf8;border-color:rgba(56,189,248,.3)',
                        'customer': 'rgba(148,163,184,.15);color:#94a3b8;border-color:rgba(148,163,184,.3)'
                    };
                    var rs = roleColors[e[i].role] || 'rgba(148,163,184,.15);color:#94a3b8;border-color:rgba(148,163,184,.3)';
                    e[i].role_label = '<span class="badge-glass" style="background:' + rs + ';white-space:nowrap">' + e[i].role_label + '</span>';
                    if (e[i].role === 'sales' && e[i].commission_rate_label) {
                        var isCustom = e[i].commission_rate_override != null;
                        var commStyle = isCustom
                            ? 'background:rgba(251,191,36,.15);color:#fbbf24;border-color:rgba(251,191,36,.35);white-space:nowrap'
                            : 'white-space:nowrap';
                        e[i].commission_label = '<span class="badge-glass small" style="' + commStyle + '">'
                            + escHtml(e[i].commission_rate_label) + '</span>';
                    } else {
                        e[i].commission_label = '<span class="small text-muted">-</span>';
                    }
                    e[i].created_at = '<span class="small">' + (e[i].created_at || '-') + '</span>';
                    var btns = '';
                    if (canManageUser) {
                        btns += renderSubordinatesActionBtn(e[i]);
                        btns += '<a class="btn btn-glass btn-sm btn_edit" href="javascript:void(0);"' + tipAttrs('tooltip_edit', 'Ubah') + '><i class="bi bi-pencil"></i></a>';
                        if (!e[i].is_self) {
                            btns += '<a class="btn btn-glass btn-sm text-danger btn_delete" href="javascript:void(0);"' + tipAttrs('tooltip_delete', 'Hapus') + '><i class="bi bi-trash"></i></a>';
                        }
                    }
                    e[i].action = '<div class="d-flex gap-1 justify-content-center align-items-center flex-nowrap user-table-actions">' + btns + '</div>';
                }
                table.rows.add(e).draw();
            },
            error: function (err) { console.error(t('failed_load_user', 'Failed to load users'), err); }
        });
    }

    function simpanData() {
        var btn = document.getElementById('btnSave'), spinner = document.getElementById('btnSpinner');
        btn.disabled = true; spinner.classList.remove('d-none');
        $('.is-invalid').removeClass('is-invalid');
        var url = '/user';
        var param = {};
        $('#dataForm').serializeArray().forEach(function (f) { if (f.value) param[f.name] = f.value; });
        param._token = token;
        param.commission_rate_override = $('#commissionRateGroup').hasClass('d-none')
            ? ''
            : $('#commissionRateOverride').val();
        if (mode == 2) { url = '/user/' + $('#formModal').attr('data-edit-id'); param._method = 'PUT'; }
        $.ajax({
            url: url, data: param, method: 'post',
            success: function (e) { bootstrap.Modal.getInstance(document.getElementById('formModal')).hide(); notifikasi('success', mode == 1 ? t('success_add', 'Added') : t('success_update', 'Updated'), e.message); refreshData(); },
            error: function (xhr) {
                var json = xhr.responseJSON;
                if (json && json.errors) { Object.keys(json.errors).forEach(function (f) { $('#dataForm [name="' + f + '"]').addClass('is-invalid'); }); notifikasi('error', t('generic_failed', 'Failed'), Object.values(json.errors).flat().join('\n')); }
                else { notifikasi('error', t('generic_failed', 'Failed'), json ? json.message : t('save_failed', 'Failed to save.')); }
            },
            complete: function () { btn.disabled = false; spinner.classList.add('d-none'); }
        });
    }

    function hapusData(id) {
        $.ajax({
            url: '/user/' + id, data: { _token: token }, method: 'delete',
            success: function (e) { notifikasi('success', t('success_delete', 'Deleted'), e.message); refreshData(); },
            error: function (xhr) { notifikasi('error', t('generic_failed', 'Failed'), xhr.responseJSON ? xhr.responseJSON.message : t('delete_failed', 'Failed to delete.')); }
        });
    }

    function renderSubordinatesActionBtn(row) {
        if (!canManageUser || !subordinatesTableReady) {
            return '';
        }
        var count = row && row.subordinate_count ? parseInt(row.subordinate_count, 10) : 0;
        var tip = t('tooltip_subordinates', 'Atur anak buah');
        if (count > 0) {
            tip = tip + ' (' + count + ')';
        }
        var tipEsc = escAttr(tip);
        var badge = count > 0
            ? '<span class="badge rounded-pill badge-subordinates-count" aria-hidden="true">' + count + '</span>'
            : '';
        return '<div class="d-inline-flex align-items-center gap-1 user-subordinates-action">'
            + '<a class="btn btn-glass btn-sm btn_subordinates" href="javascript:void(0);"'
            + ' data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="' + tipEsc + '" title="' + tipEsc + '">'
            + '<i class="bi bi-diagram-3"></i></a>'
            + badge
            + '</div>';
    }

    function openSubordinatesManage(data) {
        if (!data || !data.id) {
            return;
        }
        subordinatesUserId = data.id;
        subordinatesParentName = data.raw_name || data.name || '';
        $('#subordinatesModalTitle').html(
            '<i class="bi bi-diagram-3 me-2 text-accent"></i>' + t('subordinates_title', 'Subordinates')
        );
        $('#subordinatesModalSubtitle').text(subordinatesParentName);
        destroySubordinatesSelect();
        updateSubordinatesBadge(0);
        $('#btnClearSubordinatesInModal').addClass('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('subordinatesModal')).show();
    }

    function openSubordinatesView(data) {
        if (!data || !data.id) {
            return;
        }
        subordinatesUserId = data.id;
        subordinatesParentName = data.raw_name || data.name || '';
        $('#subordinatesViewSubtitle').text(subordinatesParentName);
        $('#subordinatesViewLoading').removeClass('d-none');
        $('#subordinatesViewList').addClass('d-none').empty();
        $('#subordinatesViewEmpty').addClass('d-none');
        $('#btnClearSubordinatesFromView').addClass('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('subordinatesViewModal')).show();
        $.getJSON('/user/' + data.id + '/subordinates', function (res) {
            $('#subordinatesViewLoading').addClass('d-none');
            var users = res.selected_users || [];
            if (users.length === 0) {
                $('#subordinatesViewEmpty').removeClass('d-none');
                return;
            }
            var $list = $('#subordinatesViewList').removeClass('d-none');
            users.forEach(function (u) {
                $list.append(
                    '<li class="list-group-item d-flex align-items-center justify-content-between gap-2 px-0">'
                    + '<div><span class="fw-600">' + escHtml(u.name) + '</span>'
                    + '<span class="small text-muted d-block">@' + escHtml(u.username) + '</span></div>'
                    + '<span class="badge-glass small">' + escHtml(u.role_label || '—') + '</span>'
                    + '</li>'
                );
            });
            $('#btnClearSubordinatesFromView').removeClass('d-none');
        }).fail(function (xhr) {
            $('#subordinatesViewLoading').addClass('d-none');
            var msg = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : t('failed_load_user', 'Failed to load users');
            notifikasi('error', t('generic_failed', 'Failed'), msg);
        });
    }

    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function confirmClearSubordinates(userId, parentName) {
        Swal.fire({
            title: t('subordinates_clear_confirm_title', 'Hapus semua anak buah?'),
            text: (t('subordinates_clear_confirm_text', 'Semua anak buah pada :name akan dilepas.'))
                .replace(':name', parentName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: t('subordinates_clear_all', 'Hapus Semua'),
            cancelButtonText: t('cancel', 'Cancel'),
        }).then(function (result) {
            if (result.isConfirmed) {
                syncSubordinates(userId, [], true);
            }
        });
    }

    function syncSubordinates(userId, ids, closeModals) {
        $.ajax({
            url: '/user/' + userId + '/subordinates',
            method: 'post',
            data: { _token: token, subordinate_ids: normalizeSubordinateIds(ids) },
            success: function (res) {
                if (closeModals) {
                    bootstrap.Modal.getInstance(document.getElementById('subordinatesModal'))?.hide();
                    bootstrap.Modal.getInstance(document.getElementById('subordinatesViewModal'))?.hide();
                }
                notifikasi('success', t('success_update', 'Updated'), res.message);
                refreshData();
            },
            error: function (xhr) {
                notifikasi('error', t('generic_failed', 'Failed'), ajaxErrorMessage(xhr, t('save_failed', 'Failed to save.')));
            },
        });
    }

    function toggleCommissionRateField() {
        var role = $('#selRole').val();
        var show = role === 'sales';
        $('#commissionRateGroup').toggleClass('d-none', !show);
        if (!show) {
            $('#commissionRateOverride').val('');
        }
    }

    function destroySubordinatesSelect() {
        var $sel = $('#selSubordinates');
        if ($sel.hasClass('select2-hidden-accessible')) {
            $sel.off('.subordinates');
            $sel.select2('destroy');
        }
    }

    function initSubordinatesSelect() {
        var $sel = $('#selSubordinates');
        var $modal = $('#subordinatesModal');
        if (typeof $.fn.select2 !== 'function') {
            return;
        }
        destroySubordinatesSelect();
        $sel.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: $sel.data('placeholder') || t('subordinates_placeholder', 'Search and pick users...'),
            allowClear: true,
            closeOnSelect: false,
            dropdownParent: $modal,
        });
        $sel.on('change.subordinates', function () {
            var vals = $sel.val() || [];
            var count = vals.filter(function (v) { return v !== '' && v != null; }).length;
            updateSubordinatesBadge(count);
        });
        $sel.trigger('change.subordinates');
    }

    function updateSubordinatesBadge(count) {
        var $badge = $('#subordinatesSelectedBadge');
        if (count > 0) {
            $badge.removeClass('d-none').text(
                (t('subordinates_selected', ':count selected')).replace(':count', String(count))
            );
        } else {
            $badge.addClass('d-none').text('0');
        }
    }

    function setSubordinatesLoading(loading) {
        $('#subordinatesSelectLoading').toggleClass('d-none', !loading);
        $('#subordinatesSelectWrap').toggleClass('is-loading', loading);
        $('#selSubordinates').prop('disabled', loading);
        if ($('#selSubordinates').hasClass('select2-hidden-accessible')) {
            $('#selSubordinates').prop('disabled', loading);
        }
    }

    function loadSubordinates(userId) {
        var $sel = $('#selSubordinates');
        destroySubordinatesSelect();
        $sel.empty().append('<option value=""></option>');
        setSubordinatesLoading(true);
        $.getJSON('/user/' + userId + '/subordinates', function (res) {
            (res.options || []).forEach(function (opt) {
                $sel.append(new Option(opt.text, String(opt.id), false, false));
            });
            var selected = (res.selected || []).map(String);
            initSubordinatesSelect();
            $sel.val(selected).trigger('change');
            if (selected.length > 0) {
                $('#btnClearSubordinatesInModal').removeClass('d-none');
            } else {
                $('#btnClearSubordinatesInModal').addClass('d-none');
            }
            setSubordinatesLoading(false);
        }).fail(function (xhr) {
            setSubordinatesLoading(false);
            notifikasi('error', t('generic_failed', 'Failed'), ajaxErrorMessage(xhr, t('failed_load_user', 'Failed to load users')));
        });
    }
});
