document.addEventListener('DOMContentLoaded', function () {
    function t(key, replacements) {
        var s = (window.__t && window.__t[key]) || key;
        if (replacements) {
            Object.keys(replacements).forEach(function (k) {
                s = String(s).replace(':' + k, replacements[k]);
            });
        }
        return s;
    }

    function escAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function tipAttrs(key, fallback) {
        var tip = escAttr((window.__t && window.__t[key]) || fallback || key);
        return ' data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="' + tip + '" title="' + tip + '"';
    }

    var table;
    var allData = [];

    initTable();
    refreshData();

    // ── Filter Logic ──
    var filterTimer;
    $('#filterSearch').on('input', function () {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(applyFilters, 200);
    });
    $('#filterStatus, #filterSort').on('change', applyFilters);
    $('#btnResetFilter').on('click', function () {
        $('#filterSearch').val('');
        $('#filterStatus').val('');
        $('#filterSort').val('newest');
        applyFilters();
    });

    function applyFilters() {
        var search = ($('#filterSearch').val() || '').toLowerCase().trim();
        var status = $('#filterStatus').val();
        var sort = $('#filterSort').val();

        var filtered = allData.filter(function (row) {
            if (status && row._status !== status) return false;
            if (search) {
                var haystack = [
                    row._container_no, row._no_bl, row._partai,
                    row._creator_name, row._gudang_china_name
                ].join(' ').toLowerCase();
                if (haystack.indexOf(search) === -1) return false;
            }
            return true;
        });

        if (sort === 'oldest') filtered.reverse();
        else if (sort === 'code-asc') filtered.sort(function (a, b) { return (a._partai || '').localeCompare(b._partai || ''); });
        else if (sort === 'code-desc') filtered.sort(function (a, b) { return (b._partai || '').localeCompare(a._partai || ''); });
        else if (sort === 'etd-asc') filtered.sort(function (a, b) { return (a._etd_raw || '').localeCompare(b._etd_raw || ''); });

        table.clear().rows.add(filtered).draw();
        $('#countNum').text(filtered.length);
        updateFilterPills(search, status);
    }

    function updateFilterPills(search, status) {
        var html = '';
        if (search) {
            html += '<span class="filter-pill"><i class="bi bi-search"></i>' + escHtml(search) +
                '<button onclick="$(\'#filterSearch\').val(\'\');$(\'#filterSearch\').trigger(\'input\')">&times;</button></span>';
        }
        if (status) {
            html += '<span class="filter-pill"><i class="bi bi-funnel"></i>' + escHtml(status) +
                '<button onclick="$(\'#filterStatus\').val(\'\').trigger(\'change\')">&times;</button></span>';
        }
        $('#activeFilters').html(html);
    }

    function escHtml(s) {
        return $('<span>').text(s).html();
    }

    function toDateInput(val) {
        if (!val) {
            return '';
        }
        return String(val).substring(0, 10);
    }

    function initContainerEditSelect2() {
        if (!window.initSelect2) {
            return;
        }
        window.initSelect2('#containerEditModal .select2-edit', {
            dropdownParent: $('#containerEditModal'),
            width: '100%',
        });
    }

    function fillContainerEditForm(container) {
        var $form = $('#containerEditForm');
        $('#editContainerId').val(container.id);
        $('#editPartai').val(container.partai || '');
        if (typeof _isSuperAdmin !== 'undefined' && _isSuperAdmin) {
            $('#editContainerType').val(container.type || '').trigger('change');
        } else {
            $('#editType').val(container.type || '');
        }
        $form.find('[name="container_no"]').val(container.container_no || '');
        $form.find('[name="no_bl"]').val(container.no_bl || '');
        $form.find('[name="load_date"]').val(toDateInput(container.load_date));
        $form.find('[name="etd"]').val(toDateInput(container.etd));
        $form.find('[name="eta"]').val(toDateInput(container.eta));
        $form.find('[name="insurance_value"]').val(container.insurance_value ?? '');
        $form.find('[name="insurance_rate"]').val(container.insurance_rate ?? '');
        $form.find('[name="insurance_date"]').val(toDateInput(container.insurance_date));
        $form.find('[name="gate_pass_date"]').val(toDateInput(container.gate_pass_date));
        $form.find('[name="notes"]').val(container.notes || '');
        $('#editIsJoin').prop('checked', !!container.is_join);

        $form.find('[name="gudang_china_id"]').val(container.gudang_china_id || '').trigger('change');
        $form.find('[name="route"]').val(container.route || '').trigger('change');
        $form.find('[name="port_id"]').val(container.port_id || '').trigger('change');
        $form.find('[name="department_item"]').val(container.department_item || '').trigger('change');
    }

    function initTable() {
        table = $('#containerTable').DataTable({
            dom: 'rt<"dt-bottom"lip>',
            ordering: false,
            pageLength: 25,
            columns: [
                { data: 'gudang_china_name' },
                { data: 'container_no' },
                { data: 'partai' },
                { data: 'creator_name' },
                { data: 'load_date' },
                { data: 'etd' },
                { data: 'eta' },
                { data: 'action', className: 'text-center' }
            ],
            language: {
                sLengthMenu: t('dt_per_page'),
                info: t('dt_info'),
                infoEmpty: t('dt_info_empty'),
                emptyTable: '<div class="py-4 text-center"><i class="bi bi-truck" style="font-size:2rem;opacity:.4"></i><div class="mt-2" style="color:var(--text-muted)">' + escHtml(t('empty_containers_dt')) + '</div></div>',
                paginate: { next: '&raquo;', previous: '&laquo;' }
            }
        });
        table.on('draw.dt', function () {
            if (window.initBsTooltips) {
                window.initBsTooltips('#containerTable');
            }
        });
    }

    $(document).on('click', '.btn_delete_container', function () {
        var data = table.row($(this).parents('tr')).data();
        if (!data || !data.id) return;

        Swal.fire({
            title: (window.__t && window.__t.delete_container_title) || 'Hapus Container?',
            text: ((window.__t && window.__t.delete_container_text) || 'Container ":name" akan dihapus permanen.').replace(':name', data.partai_raw || data._partai || ''),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: (window.__t && window.__t.confirm_delete) || 'Ya, Hapus',
            cancelButtonText: (window.__t && window.__t.cancel) || 'Batal'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: '/container/' + data.id,
                method: 'delete',
                data: { _token: typeof token !== 'undefined' ? token : '' },
                success: function (res) {
                    if (window.notifikasi) {
                        notifikasi('success', (window.__t && window.__t.success_delete) || 'Berhasil', res.message || ((window.__t && window.__t.deleted_ok) || 'Berhasil dihapus'));
                    }
                    refreshData();
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || ((window.__t && window.__t.delete_failed) || 'Gagal menghapus.');
                    if (window.notifikasi) {
                        notifikasi('error', (window.__t && window.__t.generic_failed) || 'Gagal', msg);
                    }
                }
            });
        });
    });

    function refreshData() {
        $.ajax({
            url: '/container/data', method: 'get',
            success: function (e) {
                allData = [];
                for (var i = 0; i < e.length; i++) {
                    var row = e[i];

                    row._gudang_china_name = row.gudang_china_name || '';
                    row._container_no = row.container_no || '';
                    row._no_bl = row.no_bl || '';
                    row._partai = row.partai || row.code || '';
                    row._creator_name = row.creator_name || '';
                    row._status = row.status || 'open';
                    row._etd_raw = row.etd_date || '';
                    row._can_delete = row.can_delete !== false;
                    row._can_edit = row.can_edit !== false;

                    row.gudang_china_name = row.gudang_china_name
                        ? '<span class="small" style="color:var(--text-primary)">' + row.gudang_china_name + '</span>'
                        : '<span class="small" style="color:var(--text-dim)">-</span>';

                    var contHtml = '<div class="fw-600" style="color:var(--text-primary)">' + (row.container_no || '-') + '</div>';
                    if (row.no_bl) contHtml += '<div style="font-size:.72rem;color:var(--text-muted)">' + escHtml(t('no_bl_prefix')) + ' ' + row.no_bl + '</div>';
                    row.container_no = contHtml;

                    row.partai_raw = row._partai;
                    row.partai = '<div class="fw-700 text-accent">' + row._partai + '</div>' +
                        '<div class="small" style="color:var(--text-muted)">' + escHtml(t('items_ctns_suffix', { items: row.item_count, ctns: row.total_ctns })) + '</div>';

                    row.creator_name = '<span class="small" style="color:var(--text-muted)">' + row.creator_name + '</span>';
                    row.load_date = '<span class="small" style="color:var(--text-muted)">' + (row.load_date || '-') + '</span>';
                    row.etd = row.etd ? '<span class="small" style="color:var(--text-muted)">' + row.etd + '</span>' : '<span class="small text-danger">-</span>';
                    row.eta = row.eta ? '<span class="small" style="color:var(--text-muted)">' + row.eta + '</span>' : '<span class="small text-danger">-</span>';

                    var actionBtns =
                        '<a href="/container/' + row.id + '/detail" class="btn btn-glass btn-sm"' + tipAttrs('tooltip_detail') + '>' +
                        '<i class="bi bi-box-seam"></i></a>';

                    if (typeof _canEditContainer !== 'undefined' && _canEditContainer) {
                        if (row._can_edit) {
                            actionBtns += '<a class="btn btn-glass btn-sm btn_edit_container" href="javascript:void(0);" data-id="' + row.id + '"' +
                                tipAttrs('tooltip_edit', 'Ubah data container') + '><i class="bi bi-pencil"></i></a>';
                        } else {
                            actionBtns += '<span class="btn btn-glass btn-sm disabled opacity-50" style="pointer-events:auto;cursor:not-allowed"' +
                                tipAttrs('tooltip_edit_locked', 'Container sudah berangkat, tidak dapat diubah') + '>' +
                                '<i class="bi bi-pencil"></i></span>';
                        }
                    }

                    if (typeof _canDeleteContainer !== 'undefined' && _canDeleteContainer) {
                        actionBtns += '<a class="btn btn-glass btn-sm text-danger btn_delete_container" href="javascript:void(0);"' +
                            tipAttrs('tooltip_delete') + '><i class="bi bi-trash"></i></a>';
                    }

                    row.action = '<div class="d-flex gap-1 justify-content-center">' + actionBtns + '</div>';

                    allData.push(row);
                }
                table.clear().rows.add(allData).draw();
                $('#countNum').text(allData.length);

                if (pendingEditId) {
                    var pendingRow = allData.find(function (row) {
                        return String(row.id) === String(pendingEditId);
                    });
                    openContainerEditModal(pendingEditId, pendingRow?.partai_raw || '');
                    pendingEditId = null;
                }
            },
            error: function (err) { console.error(t('failed_load_containers'), err); }
        });
    }

    function openContainerEditModal(id, partaiLabel) {
        var showUrl = (window._containerEditShowUrl || '').replace('__CONTAINER_ID__', String(id));
        if (!showUrl || showUrl.indexOf('__CONTAINER_ID__') !== -1) {
            return;
        }

        if (!$('#containerEditModal').length) {
            return;
        }

        $('#containerEditForm')[0].reset();
        $('#containerEditModalTitle').text(
            (t('edit_title') || 'Ubah Container') + (partaiLabel ? ' — ' + partaiLabel : '')
        );

        Swal.fire({
            title: t('loading') || 'Memuat...',
            allowOutsideClick: false,
            didOpen: function () { Swal.showLoading(); },
        });

        $.get(showUrl, function (res) {
            Swal.close();
            if (!res.container) {
                Swal.fire({
                    icon: 'error',
                    title: t('failed') || 'Gagal',
                    text: t('generic_failed') || 'Data container tidak ditemukan.',
                });
                return;
            }
            initContainerEditSelect2();
            fillContainerEditForm(res.container);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('containerEditModal')).show();
        }).fail(function (xhr) {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: t('failed') || 'Gagal',
                text: xhr.responseJSON?.message || t('failed_load_data') || 'Gagal memuat data container.',
            });
        });
    }

    $(document).on('click', '.btn_edit_container', function () {
        var rowData = table.row($(this).parents('tr')).data();
        openContainerEditModal($(this).data('id'), rowData?.partai_raw || '');
    });

    var pendingEditId = new URLSearchParams(window.location.search).get('edit');
    if (pendingEditId) {
        var cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }

    $('#containerEditForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#editContainerId').val();
        var updateUrl = (window._containerEditUpdateUrl || '').replace('__CONTAINER_ID__', String(id));
        if (!updateUrl || updateUrl.indexOf('__CONTAINER_ID__') !== -1) {
            return;
        }

        var $btn = $('#btnSaveContainerEdit');
        var $spinner = $('#containerEditSpinner');
        $btn.prop('disabled', true);
        $spinner.removeClass('d-none');

        var payload = $(this).serializeArray().filter(function (item) {
            return item.name !== 'is_join';
        });
        payload.push({ name: '_method', value: 'PUT' });
        payload.push({ name: 'is_join', value: $('#editIsJoin').is(':checked') ? '1' : '0' });

        $.ajax({
            url: updateUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token },
            data: $.param(payload),
            success: function (res) {
                bootstrap.Modal.getInstance(document.getElementById('containerEditModal'))?.hide();
                Swal.fire({
                    icon: 'success',
                    title: t('success_update') || 'Berhasil',
                    text: res.message || t('data_saved') || 'Data berhasil disimpan',
                    timer: 2000,
                    timerProgressBar: true,
                });
                refreshData();
            },
            error: function (xhr) {
                Swal.fire({
                    icon: 'error',
                    title: t('failed') || 'Gagal',
                    text: xhr.responseJSON?.message || t('save_failed') || 'Gagal menyimpan.',
                });
            },
            complete: function () {
                $btn.prop('disabled', false);
                $spinner.addClass('d-none');
            },
        });
    });

});
