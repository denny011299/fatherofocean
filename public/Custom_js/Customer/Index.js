document.addEventListener('DOMContentLoaded', function () {
    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    var mode = 1;
    var table;

    inisialisasi();
    refreshData();

    $(document).on('click', '.btnAdd', function () {
        mode = 1;
        $('#formModalTitle').html('<i class="bi bi-person-plus me-2 text-accent"></i>' + t('add', 'Add Customer'));
        $('#dataForm')[0].reset();
        $('[name="password"]').attr('required', 'required');
        $('#passwordLabel').html('Password <span class="text-danger">*</span>');
        $('.is-invalid').removeClass('is-invalid');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $('#dataForm').on('submit', function (e) {
        e.preventDefault();
        simpanData();
    });

    $(document).on('click', '.btn_edit', function () {
        var data = table.row($(this).parents('tr')).data();
        mode = 2;
        $('#formModalTitle').html('<i class="bi bi-person-gear me-2 text-accent"></i>Edit Customer');
        $('.is-invalid').removeClass('is-invalid');
        $('[name="name"]').val(data.raw_name);
        $('[name="username"]').val(data.raw_username);
        $('[name="email"]').val(data.raw_email || '');
        $('[name="phone"]').val(data.raw_phone || '');
        $('[name="address"]').val(data.raw_address || '');
        $('[name="password"]').val('').removeAttr('required');
        $('#passwordLabel').html('Password <small class="text-muted">(kosongkan jika tidak diubah)</small>');
        $('#formModal').attr('data-edit-id', data.id);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $(document).on('click', '.btn_delete', function () {
        var data = table.row($(this).parents('tr')).data();
        Swal.fire({
            title: t('delete_customer_title', 'Delete customer?'), text: (t('delete_customer_text', 'Customer ":name" will be permanently deleted.')).replace(':name', data.raw_name),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280',
            confirmButtonText: t('confirm_delete', 'Yes, Delete'), cancelButtonText: t('cancel', 'Cancel')
        }).then(function (result) {
            if (result.isConfirmed) hapusData(data.id);
        });
    });

    function inisialisasi() {
        table = $('#customerTable').DataTable({
            dom: '<"dt-top"f>rt<"dt-bottom"lip>', ordering: true, pageLength: 25,
            columns: [
                { data: "name" }, { data: "username" }, { data: "email" }, { data: "phone" },
                { data: "address" }, { data: "markings_count" }, { data: "created_at" },
                { data: "action", className: 'text-center' }
            ],
            language: { search: '', searchPlaceholder: 'Cari...', sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data', infoEmpty: 'Tidak ada data', emptyTable: 'Tidak ada data tersedia',
                paginate: { next: '&raquo;', previous: '&laquo;' } }
        });
    }

    function refreshData() {
        $.ajax({
            url: '/customer/data', method: 'get',
            success: function (e) {
                table.clear().draw();
                for (var i = 0; i < e.length; i++) {
                    e[i].raw_name = e[i].raw_name || e[i].name;
                    e[i].raw_username = e[i].raw_username || e[i].username;
                    e[i].raw_email = e[i].email || '';
                    e[i].raw_phone = e[i].phone || '';
                    e[i].raw_address = e[i].address || '';
                    e[i].name = '<span class="fw-600" style="color:var(--text-primary)">' + e[i].raw_name + '</span>';
                    e[i].username = '<code>' + e[i].raw_username + '</code>';
                    e[i].email = '<span class="small">' + (e[i].raw_email || '-') + '</span>';
                    e[i].phone = '<span class="small">' + (e[i].raw_phone || '-') + '</span>';
                    e[i].address = '<span class="small" style="max-width:150px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (e[i].raw_address || '-') + '</span>';
                    e[i].markings_count = '<span class="badge bg-primary bg-opacity-10 text-primary">' + e[i].markings_count + ' marking</span>';
                    e[i].created_at = '<span class="small">' + (e[i].created_at || '-') + '</span>';
                    var actionBtns = '';
                    if (typeof _canManageCustomer !== 'undefined' && _canManageCustomer) {
                        actionBtns += '<a class="btn btn-glass btn-sm btn_edit" href="javascript:void(0);" title="Edit"><i class="bi bi-pencil"></i></a>';
                        actionBtns += '<a class="btn btn-glass btn-sm text-danger btn_delete" href="javascript:void(0);" title="Hapus"><i class="bi bi-trash"></i></a>';
                    }
                    e[i].action = '<div class="d-flex gap-1 justify-content-center">' + actionBtns + '</div>';
                }
                table.rows.add(e).draw();
            },
            error: function (err) { console.error(t('failed_load_customer', 'Failed to load customers'), err); }
        });
    }

    function simpanData() {
        var btn = document.getElementById('btnSave'), spinner = document.getElementById('btnSpinner');
        btn.disabled = true; spinner.classList.remove('d-none');
        $('.is-invalid').removeClass('is-invalid');
        var url = '/customer';
        var param = {};
        $('#dataForm').serializeArray().forEach(function (f) { if (f.value) param[f.name] = f.value; });
        param._token = token;
        if (mode == 2) { url = '/customer/' + $('#formModal').attr('data-edit-id'); param._method = 'PUT'; }
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
            url: '/customer/' + id, data: { _token: token }, method: 'delete',
            success: function (e) { notifikasi('success', t('success_delete', 'Deleted'), e.message); refreshData(); },
            error: function (xhr) { notifikasi('error', t('generic_failed', 'Failed'), xhr.responseJSON ? xhr.responseJSON.message : t('delete_failed', 'Failed to delete.')); }
        });
    }
});
