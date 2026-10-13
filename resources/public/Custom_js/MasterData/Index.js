document.addEventListener('DOMContentLoaded', function () {
    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    var mode = 1;
    var table;

    inisialisasi();
    refreshData();

    if (PAGE_TYPE === 'promos') {
        initPromoTypeFields();
    }

    function initPromoTypeFields() {
        syncPromoTypeFields($('#dataForm [name="type"]').val() || 'percent');
        $(document).on('change', '#dataForm [name="type"]', function () {
            syncPromoTypeFields($(this).val());
        });
    }

    function syncPromoTypeFields(type) {
        $('#dataForm .promo-field-group').each(function () {
            var showType = $(this).data('promo-show-type');
            var visible = showType === type;
            $(this).toggle(visible);
            var $input = $(this).find('input, select, textarea').first();
            $input.prop('required', visible);
            if (!visible) {
                $input.val('');
            }
        });
    }

    function resetIncentiveCategorySelect() {
        var $select = $('#dataForm [name="pricing_item_id"]');
        if (!$select.length) {
            return;
        }
        var defaultVal = $select.find('option[value="all"]').length ? 'all' : '';
        $select.val(defaultVal).trigger('change');
    }

    $(document).on('click', '.btnAdd', function () {
        mode = 1;
        $('#formModalTitle').text(t('add_data', 'Add Record'));
        $('#dataForm')[0].reset();
        $('.is-invalid').removeClass('is-invalid');
        resetIncentiveCategorySelect();
        if (PAGE_TYPE === 'promos') {
            $('#dataForm [name="type"]').val('percent');
            syncPromoTypeFields('percent');
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $('#dataForm').on('submit', function (e) {
        e.preventDefault();
        simpanData();
    });

    $(document).on('click', '.btn_edit', function () {
        var data = table.row($(this).parents('tr')).data();
        mode = 2;
        $('#formModalTitle').text('Edit Data');
        $('.is-invalid').removeClass('is-invalid');
        PAGE_FIELDS.forEach(function (f) {
            var $el = $('#dataForm [name="' + f.name + '"]');
            $el.val(data['raw_' + f.name] || data[f.name] || '');
            if ($el.is('select')) {
                $el.trigger('change');
            }
        });
        if (PAGE_TYPE === 'promos') {
            syncPromoTypeFields($('#dataForm [name="type"]').val() || 'percent');
        }
        $('#formModal').attr('data-edit-id', data.id);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $(document).on('click', '.btn_toggle', function () {
        var id = $(this).data('id');
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '/master-data/' + PAGE_TYPE + '/' + id + '/toggle',
            method: 'PATCH',
            data: { _token: token },
            success: function (res) {
                notifikasi('success', t('success_update', 'Updated'), res.message || t('data_saved', 'Saved'));
                refreshData();
            },
            error: function (xhr) {
                notifikasi('error', t('generic_failed', 'Failed'), xhr.responseJSON ? xhr.responseJSON.message : t('save_failed', 'Failed to save.'));
            },
            complete: function () {
                $btn.prop('disabled', false);
            },
        });
    });

    $(document).on('click', '.btn_delete', function () {
        var data = table.row($(this).parents('tr')).data();
        Swal.fire({
            title: t('delete_master_title', 'Delete record?'), text: (t('delete_master_text', 'Record ":name" will be permanently deleted.')).replace(':name', data.name || data.code || ''),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280',
            confirmButtonText: t('confirm_delete', 'Yes, Delete'), cancelButtonText: t('cancel', 'Cancel')
        }).then(function (result) {
            if (result.isConfirmed) hapusData(data.id, data.name || data.code || '');
        });
    });

    function statusBadge(row) {
        var active = !!row.is_active;
        var label = row.status_label || (active ? t('status_active', 'Aktif') : t('status_inactive', 'Nonaktif'));
        return '<span class="badge ' + (active ? 'bg-success' : 'bg-secondary') + '">' + label + '</span>';
    }

    function inisialisasi() {
        var cols = [];
        PAGE_COLUMNS.forEach(function (c) {
            var col = { data: c.key, title: c.label };
            if (c.key === 'status_label') {
                col.orderable = false;
                col.render = function (data, type, row) {
                    return statusBadge(row);
                };
            }
            cols.push(col);
        });
        cols.push({
            data: 'action',
            title: t('actions', 'Aksi'),
            orderable: false,
            width: PAGE_HAS_TOGGLE ? '130px' : '80px',
            className: 'text-center',
        });
        table = $('#masterTable').DataTable({
            dom: '<"dt-top"f>rt<"dt-bottom"lip>', ordering: true, pageLength: 25, columns: cols,
            language: { search: '', searchPlaceholder: 'Cari...', sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data', infoEmpty: 'Tidak ada data', emptyTable: 'Tidak ada data tersedia',
                paginate: { next: '&raquo;', previous: '&laquo;' } }
        });
    }

    function refreshData() {
        $.ajax({
            url: '/master-data/' + PAGE_TYPE + '/data', method: 'get',
            success: function (e) {
                table.clear().draw();
                for (var i = 0; i < e.length; i++) {
                    var actionBtns = '';
                    if (PAGE_HAS_TOGGLE && typeof _canManageMasterData !== 'undefined' && _canManageMasterData) {
                        var on = !!e[i].is_active;
                        actionBtns += '<button type="button" class="btn btn-sm ' + (on ? 'btn-accent' : 'btn-glass') + ' btn_toggle" data-id="' + e[i].id + '" title="' + (on ? t('toggle_off', 'Matikan') : t('toggle_on', 'Nyalakan')) + '">'
                            + '<i class="bi ' + (on ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>';
                    }
                    if (typeof _canManageMasterData !== 'undefined' && _canManageMasterData) {
                        actionBtns += '<a class="btn btn-glass btn-sm btn_edit" href="javascript:void(0);" title="Edit"><i class="bi bi-pencil"></i></a>';
                    }
                    if (typeof _canDeleteMasterData !== 'undefined' && _canDeleteMasterData) {
                        actionBtns += '<a class="btn btn-glass btn-sm text-danger btn_delete" href="javascript:void(0);" title="Hapus"><i class="bi bi-trash"></i></a>';
                    }
                    e[i].action = '<div class="d-flex gap-1 justify-content-center">' + actionBtns + '</div>';
                }
                table.rows.add(e).draw();
            },
            error: function (err) { console.error(t('failed_load_data', 'Failed to load data'), err); }
        });
    }

    function collectFormParams() {
        var param = {};
        $('#dataForm').serializeArray().forEach(function (f) {
            if (Object.prototype.hasOwnProperty.call(param, f.name)) {
                if (!Array.isArray(param[f.name])) {
                    param[f.name] = [param[f.name]];
                }
                param[f.name].push(f.value);
                return;
            }
            param[f.name] = f.value;
        });

        return param;
    }

    function simpanData() {
        var btn = document.getElementById('btnSave'), spinner = document.getElementById('btnSpinner');
        btn.disabled = true; spinner.classList.remove('d-none');
        $('.is-invalid').removeClass('is-invalid');
        var url = '/master-data/' + PAGE_TYPE;
        var param = collectFormParams();
        param._token = token;
        if (mode == 2) { url = '/master-data/' + PAGE_TYPE + '/' + $('#formModal').attr('data-edit-id'); param._method = 'PUT'; }
        $.ajax({
            url: url, data: param, method: 'post',
            success: function (e) {
                bootstrap.Modal.getInstance(document.getElementById('formModal')).hide();
                notifikasi('success', mode == 1 ? t('success_add', 'Added') : t('success_update', 'Updated'), e.message || t('data_saved', 'Saved'));
                refreshData();
            },
            error: function (xhr) {
                var json = xhr.responseJSON;
                if (json && json.errors) {
                    Object.keys(json.errors).forEach(function (f) {
                        var $field = $('#dataForm [name="' + f + '"], #dataForm [name="' + f + '[]"]');
                        $field.addClass('is-invalid');
                    });
                    notifikasi('error', t('generic_failed', 'Failed'), Object.values(json.errors).flat().join('\n'));
                } else { notifikasi('error', t('generic_failed', 'Failed'), json ? json.message : t('save_failed', 'Failed to save.')); }
            },
            complete: function () { btn.disabled = false; spinner.classList.add('d-none'); }
        });
    }

    function hapusData(id) {
        $.ajax({
            url: '/master-data/' + PAGE_TYPE + '/' + id, data: { _token: token }, method: 'delete',
            success: function (e) { notifikasi('success', t('success_delete', 'Deleted'), e.message || t('deleted_ok', 'Deleted')); refreshData(); },
            error: function (xhr) { notifikasi('error', t('generic_failed', 'Failed'), xhr.responseJSON ? xhr.responseJSON.message : t('delete_failed', 'Failed to delete.')); }
        });
    }
});
