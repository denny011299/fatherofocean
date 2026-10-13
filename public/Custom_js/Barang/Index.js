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

    function escHtml(s) {
        return $('<span>').text(s == null ? '' : String(s)).html();
    }

    var table;
    var debounceTimer;
    var listRequest = null;
    var listRequestSeq = 0;
    var restoringListState = false;
    var restoringTableState = false;
    var listStateStorageKey = 'barangListUiState_v2';

    function scheduleRefreshData(done, delayMs) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            refreshData(done);
        }, delayMs == null ? 150 : delayMs);
    }

    function readBarangListState() {
        try {
            var raw = sessionStorage.getItem(listStateStorageKey);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function writeBarangListState(state) {
        try {
            sessionStorage.setItem(listStateStorageKey, JSON.stringify(state));
        } catch (e) {
            /* ignore quota / private mode */
        }
    }

    function snapshotFilterFields() {
        var params = filterParams();
        return {
            no_index: params.no_index || '',
            no_resi: params.no_resi || '',
            status: params.status || '',
            city: params.city || '',
            gudang_china_id: params.gudang_china_id || '',
            partai: params.partai || '',
            date_preset: params.date_preset || '',
            tanggal_from: params.tanggal_from || '',
            tanggal_to: params.tanggal_to || '',
            marking: params.marking || [],
            pricing_item_id: params.pricing_item_id || [],
        };
    }

    function filterSelectAllowedValues($select, values) {
        if (!values || !values.length) {
            return [];
        }
        var allowed = {};
        $select.find('option').each(function () {
            var val = $(this).attr('value');
            if (val !== undefined && val !== '') {
                allowed[String(val)] = true;
            }
        });
        return values.map(String).filter(function (v) {
            return !!allowed[v];
        });
    }

    function setSingleSelectValue($select, value) {
        var val = value == null ? '' : String(value);
        if (!val) {
            $select.val('');
            return;
        }
        if ($select.find('option').filter(function () {
            return String($(this).val()) === val;
        }).length) {
            $select.val(val);
        } else {
            $select.val('');
        }
    }

    function applyBarangListState(state) {
        if (!state || (state.v !== 1 && state.v !== 2) || !state.filters) {
            return false;
        }

        restoringListState = true;
        try {
            var f = state.filters;
            $('#filterNoIndex').val(f.no_index || '');
            $('#filterNoResi').val(f.no_resi || '');

            var markingValues = filterSelectAllowedValues($('#filterMarking'), f.marking);
            $('#filterMarking').val(markingValues.length ? markingValues : null);

            var pricingValues = filterSelectAllowedValues($('#filterPricingItem'), f.pricing_item_id);
            $('#filterPricingItem').val(pricingValues.length ? pricingValues : null);

            setSingleSelectValue($('#filterStatus'), f.status);
            setSingleSelectValue($('#filterCity'), f.city);
            setSingleSelectValue($('#filterGudang'), f.gudang_china_id);
            setSingleSelectValue($('#filterPartai'), f.partai);

            var preset = String(f.date_preset || '');
            var allowedPresets = window.DatePresetFilter && window.DatePresetFilter.PRESETS
                ? window.DatePresetFilter.PRESETS
                : ['', 'today', 'yesterday', '7d', '30d', 'this_month', 'this_year', 'custom'];
            if (allowedPresets.indexOf(preset) === -1) {
                preset = '';
            }

            if (datePresetUi && typeof datePresetUi.setPreset === 'function') {
                datePresetUi.setPreset(preset);
            } else {
                $('#filterDatePreset').val(preset);
            }

            if (preset === 'custom') {
                if (window.DatePresetFilter && window.DatePresetFilter.toggleCustomRow) {
                    window.DatePresetFilter.toggleCustomRow($('#filterDateCustomRow'), true);
                }
                $('#filterTanggalFrom').val(f.tanggal_from || '');
                $('#filterTanggalTo').val(f.tanggal_to || '');
            }
        } finally {
            restoringListState = false;
        }

        return true;
    }

    function captureBarangListState() {
        if (restoringListState || restoringTableState || !table) {
            return;
        }

        writeBarangListState({
            v: 2,
            savedAt: Date.now(),
            filters: snapshotFilterFields(),
            table: {
                page: table.page(),
                length: table.page.len(),
                order: table.order(),
            },
            scrollY: window.scrollY || 0,
        });
    }

    function restoreBarangTableState(state) {
        if (!table || !state || !state.table) {
            return;
        }

        restoringTableState = true;
        try {
            var t = state.table;
            var length = parseInt(t.length, 10);
            if (length > 0) {
                table.page.len(length);
            }

            if (Array.isArray(t.order) && t.order.length) {
                var colCount = table.columns().count();
                var validOrder = t.order.filter(function (pair) {
                    return Array.isArray(pair)
                        && pair.length >= 2
                        && pair[0] >= 0
                        && pair[0] < colCount;
                });
                if (validOrder.length) {
                    table.order(validOrder);
                }
            }

            table.draw(false);

            var page = parseInt(t.page, 10);
            if (!isNaN(page) && page >= 0) {
                var info = table.page.info();
                if (info.pages > 0 && page < info.pages) {
                    table.page(page).draw(false);
                }
            }
        } catch (e) {
            console.warn('Gagal restore tabel barang:', e);
        } finally {
            restoringTableState = false;
        }

        var scrollY = parseInt(state.scrollY, 10);
        if (!isNaN(scrollY) && scrollY > 0) {
            window.requestAnimationFrame(function () {
                window.scrollTo(0, scrollY);
            });
        }

        captureBarangListState();
    }

    function bindTableStatePersistence() {
        if (!table) {
            return;
        }
        table.on('page.dt order.dt length.dt', function () {
            captureBarangListState();
        });
    }

    window.addEventListener('pagehide', captureBarangListState);

    function formatAjaxErrors(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        if (json && json.errors) {
            return Object.values(json.errors).flat().join('\n');
        }
        return (json && json.message) ? json.message : fallback;
    }

    function barangEditUrl(id) {
        return (typeof _barangEditUrlTemplate !== 'undefined' ? _barangEditUrlTemplate : '/barang/__ID__/edit')
            .replace('__ID__', id);
    }

    function barangStatusUpdateUrl(id) {
        return (typeof _barangStatusUpdateUrlTemplate !== 'undefined' ? _barangStatusUpdateUrlTemplate : '/tracking/__ID__/status')
            .replace('__ID__', id);
    }

    function postBarangStatusUpdate(id, status, successMessage) {
        return $.ajax({
            url: barangStatusUpdateUrl(id),
            method: 'POST',
            data: {
                _token: token,
                status: status
            }
        }).done(function (res) {
            showToast(res.message || successMessage || t('tracking_updated', 'Status tracking berhasil diperbarui.'));
            refreshData();
        }).fail(function (xhr) {
            showToast(formatAjaxErrors(xhr, t('error_generic', 'Terjadi kesalahan.')), 'error');
        });
    }

    function cellMutedDash(value) {
        var text = value == null ? '' : String(value).trim();
        if (!text) {
            return '<span class="text-muted">-</span>';
        }
        return escHtml(text);
    }

    function formatResiStack(lines) {
        if (!lines || !lines.length) {
            return '<span class="text-muted">-</span>';
        }
        var html = '<div class="barang-resi-stack">';
        for (var i = 0; i < lines.length; i++) {
            var n = lines[i] == null ? '' : String(lines[i]).trim();
            if (n) {
                html += '<div class="barang-resi-line">' + escHtml(n) + '</div>';
            }
        }
        html += '</div>';
        return html.indexOf('barang-resi-line') !== -1 ? html : '<span class="text-muted">-</span>';
    }

    function formatVolumeStack(row) {
        var coly = row.ctns != null && row.ctns !== '' ? String(row.ctns) : '0';
        var cbm = typeof window.formatCbm === 'function'
            ? window.formatCbm(row.total_cbm || 0)
            : Number(row.total_cbm || 0).toFixed(3);
        var gw = Number(row.total_gross_weight || 0).toFixed(2);
        var colyUnit = t('unit_coly', 'coly');
        var cbmUnit = t('unit_cbm', 'cbm');
        var kgUnit = t('unit_kg', 'kg');

        return '<div class="barang-volume-stack">'
            + '<div><span class="fw-600">' + escHtml(coly) + '</span> <span class="text-muted">' + escHtml(colyUnit) + '</span></div>'
            + '<div class="barang-volume-cbm">' + cbm + ' <span class="text-muted">' + escHtml(cbmUnit) + '</span></div>'
            + '<div>' + gw + ' <span class="text-muted">' + escHtml(kgUnit) + '</span></div>'
            + '</div>';
    }

    function statusLabel(status) {
        if (!status) return '-';
        var key = 'status_' + status;
        var label = t(key, '');
        if (label && label !== key) {
            return label;
        }
        return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
    }

    function compactFilterChipText(text, maxLen) {
        var raw = text == null ? '' : String(text).trim();
        if (!raw || raw.length <= maxLen) {
            return raw;
        }
        return raw.slice(0, maxLen - 1) + '…';
    }

    function markingFilterTemplateSelection(selection) {
        if (!selection.id) {
            return selection.text;
        }
        var code = String(selection.id);
        var full = selection.text || code;
        var short = compactFilterChipText(code, 22);
        return $('<span></span>').text(short).attr('title', full);
    }

    function pricingFilterTemplateSelection(selection) {
        if (!selection.id) {
            return selection.text;
        }
        var full = selection.text || '';
        var short = compactFilterChipText(full, 22);
        return $('<span></span>').text(short).attr('title', full);
    }

    function initFilterSelect2() {
        if (typeof window.initSelect2 !== 'function') {
            return;
        }
        var compactSelect2Opts = {
            allowClear: true,
            width: '100%',
            selectionCssClass: 'barang-filter-compact-select2',
            dropdownCssClass: 'barang-filter-compact-select2-dropdown',
        };
        window.initSelect2('#filterStatus, #filterCity, #filterGudang, #filterPartai', compactSelect2Opts);
        window.initSelect2('#filterMarking', Object.assign({}, compactSelect2Opts, {
            multiple: true,
            placeholder: $('#filterMarking').data('placeholder') || '',
            templateSelection: markingFilterTemplateSelection,
        }));
        window.initSelect2('#filterPricingItem', Object.assign({}, compactSelect2Opts, {
            multiple: true,
            placeholder: $('#filterPricingItem').data('placeholder') || '',
            templateSelection: pricingFilterTemplateSelection,
        }));
    }

    function selectedMarkingCodes() {
        var val = $('#filterMarking').val();
        if (!val) {
            return [];
        }
        return Array.isArray(val) ? val : [val];
    }

    function selectedPricingItemIds() {
        var val = $('#filterPricingItem').val();
        if (!val) {
            return [];
        }
        return Array.isArray(val) ? val : [val];
    }

    var datePresetUi = window.DatePresetFilter
        ? window.DatePresetFilter.init({
            presetSelector: '#filterDatePreset',
            customRowSelector: '#filterDateCustomRow',
            fromSelector: '#filterTanggalFrom',
            toSelector: '#filterTanggalTo',
            onChange: function () {
                scheduleRefreshData();
            },
        })
        : null;

    function filterParams() {
        var params = {
            no_index: ($('#filterNoIndex').val() || '').trim(),
            no_resi: ($('#filterNoResi').val() || '').trim(),
            status: $('#filterStatus').val() || '',
            city: $('#filterCity').val() || '',
            gudang_china_id: $('#filterGudang').val() || '',
            partai: $('#filterPartai').val() || '',
        };
        if (window.DatePresetFilter) {
            Object.assign(
                params,
                window.DatePresetFilter.getApiParams(
                    '#filterDatePreset',
                    '#filterTanggalFrom',
                    '#filterTanggalTo',
                    'date_preset',
                ),
            );
        }
        var markingCodes = selectedMarkingCodes();
        if (markingCodes.length) {
            params.marking = markingCodes;
        }
        var pricingItemIds = selectedPricingItemIds();
        if (pricingItemIds.length) {
            params.pricing_item_id = pricingItemIds;
        }
        return params;
    }

    function dateFilterPillLabel() {
        var preset = $('#filterDatePreset').val() || '';
        if (!preset) {
            return '';
        }
        if (preset === 'custom' && window.DatePresetFilter) {
            var from = $('#filterTanggalFrom').val() || '';
            var to = $('#filterTanggalTo').val() || '';
            if (from || to) {
                return from && to ? from + ' – ' + to : (from || to);
            }
            return window.DatePresetFilter.presetLabel('custom');
        }
        return window.DatePresetFilter
            ? window.DatePresetFilter.presetLabel(preset)
            : preset;
    }

    function renderActiveFilters() {
        var container = document.getElementById('activeFilters');
        if (!container) return;

        var pills = [];

        function addPill(icon, label, clearFn, title) {
            var tip = title ? ' title="' + escAttr(title) + '"' : '';
            pills.push(
                '<span class="filter-pill"' + tip + '><i class="bi ' + icon + '"></i> '
                + '<span class="filter-pill-label">' + escHtml(label) + '</span>'
                + '<button type="button" data-clear="' + clearFn + '">&times;</button></span>'
            );
        }

        var dateLabel = dateFilterPillLabel();
        if (dateLabel) {
            addPill('bi-calendar-range', dateLabel, 'dates');
        }

        var noIndex = ($('#filterNoIndex').val() || '').trim();
        if (noIndex) {
            addPill('bi-hash', noIndex, 'no_index');
        }

        var noResi = ($('#filterNoResi').val() || '').trim();
        if (noResi) {
            addPill('bi-upc-scan', noResi, 'no_resi');
        }

        var $markingSelect = $('#filterMarking');
        selectedMarkingCodes().forEach(function (code) {
            var fullLabel = $markingSelect.find('option[value="' + code + '"]').text();
            if (fullLabel) {
                addPill('bi-bookmark-star', String(code), 'marking:' + code, fullLabel);
            }
        });

        var statusVal = $('#filterStatus').val();
        if (statusVal) {
            addPill('bi-flag', $('#filterStatus option:selected').text(), 'status');
        }

        var pricingItemIds = selectedPricingItemIds();
        var $pricingSelect = $('#filterPricingItem');
        pricingItemIds.forEach(function (id) {
            var fullLabel = $pricingSelect.find('option[value="' + id + '"]').text();
            if (fullLabel) {
                addPill('bi-tags', compactFilterChipText(fullLabel, 28), 'pricing_item:' + id, fullLabel);
            }
        });

        var cityVal = $('#filterCity').val();
        if (cityVal) {
            addPill('bi-geo-alt', $('#filterCity option:selected').text(), 'city');
        }

        var gudangVal = $('#filterGudang').val();
        if (gudangVal) {
            addPill('bi-building', $('#filterGudang option:selected').text(), 'gudang');
        }

        var partaiVal = $('#filterPartai').val();
        if (partaiVal) {
            addPill('bi-box-seam', $('#filterPartai option:selected').text(), 'partai');
        }

        container.innerHTML = pills.join('');

        container.querySelectorAll('button[data-clear]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                clearFilter(btn.getAttribute('data-clear'));
            });
        });
    }

    function clearFilter(name) {
        switch (name) {
            case 'dates':
                if (datePresetUi) {
                    datePresetUi.clearDates();
                } else {
                    $('#filterDatePreset').val('');
                    $('#filterTanggalFrom').val('');
                    $('#filterTanggalTo').val('');
                }
                break;
            case 'no_index':
                $('#filterNoIndex').val('');
                break;
            case 'no_resi':
                $('#filterNoResi').val('');
                break;
            case 'marking':
                $('#filterMarking').val(null);
                break;
            case 'status':
                $('#filterStatus').val('');
                break;
            case 'city':
                $('#filterCity').val('');
                break;
            case 'gudang':
                $('#filterGudang').val('');
                break;
            case 'partai':
                $('#filterPartai').val('');
                break;
            default:
                if (name.indexOf('marking:') === 0) {
                    var removeCode = name.slice('marking:'.length);
                    var keepCodes = selectedMarkingCodes().filter(function (code) {
                        return String(code) !== String(removeCode);
                    });
                    $('#filterMarking').val(keepCodes.length ? keepCodes : null);
                } else if (name.indexOf('pricing_item:') === 0) {
                    var removeId = name.slice('pricing_item:'.length);
                    var keepIds = selectedPricingItemIds().filter(function (id) {
                        return String(id) !== String(removeId);
                    });
                    $('#filterPricingItem').val(keepIds.length ? keepIds : null);
                }
                break;
        }
        syncFilterSelect2Ui();
        scheduleRefreshData(null, 0);
    }

    function syncFilterSelect2Ui() {
        $('#filterMarking, #filterStatus, #filterPricingItem, #filterCity, #filterGudang, #filterPartai')
            .trigger('change.select2');
    }

    function resetAllFilters() {
        if (datePresetUi) {
            datePresetUi.reset();
        } else {
            $('#filterDatePreset').val('');
            $('#filterTanggalFrom').val('');
            $('#filterTanggalTo').val('');
        }
        $('#filterNoIndex').val('');
        $('#filterNoResi').val('');
        $('#filterMarking').val(null);
        $('#filterStatus').val('');
        $('#filterPricingItem').val(null);
        $('#filterCity').val('');
        $('#filterGudang').val('');
        $('#filterPartai').val('');
        syncFilterSelect2Ui();
        try {
            sessionStorage.removeItem(listStateStorageKey);
        } catch (e) {
            /* ignore */
        }
        scheduleRefreshData(null, 0);
    }

    var savedListState = readBarangListState();
    var hasSavedListState = false;
    if (savedListState) {
        hasSavedListState = applyBarangListState(savedListState);
    }

    inisialisasi();
    initFilterSelect2();
    if (hasSavedListState) {
        $('#filterMarking, #filterStatus, #filterPricingItem, #filterCity, #filterGudang, #filterPartai')
            .trigger('change.select2');
    }

    window.barangListOnTabShow = function () {
        if (table) {
            table.columns.adjust().draw(false);
        }
    };

    $('#filterTanggalFrom, #filterTanggalTo').on('input', function () {
        if (($('#filterDatePreset').val() || '') !== 'custom') {
            return;
        }
        scheduleRefreshData(null, 400);
    });
    $('#filterNoIndex, #filterNoResi').on('input', function () {
        scheduleRefreshData(null, 300);
    });
    $('#filterMarking, #filterStatus, #filterPricingItem, #filterCity, #filterGudang, #filterPartai')
        .on('change', function () {
            scheduleRefreshData();
        });
    $('#btnResetFilter').on('click', resetAllFilters);

    refreshData(function () {
        if (hasSavedListState && savedListState) {
            restoreBarangTableState(savedListState);
        } else {
            captureBarangListState();
        }
    });

    $('#btnExportBarangList').on('click', function (e) {
        if (typeof _barangListExportUrl === 'undefined' || !_barangListExportUrl) {
            return;
        }
        e.preventDefault();
        var params = $.param(filterParams());
        var url = _barangListExportUrl + (params ? '?' + params : '');
        window.open(url, '_blank', 'noopener');
    });

    $('#importForm').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/barang/import', type: 'POST',
            data: new FormData(this), processData: false, contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
            success: function (res) {
                bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
                notifikasi('success', t('success', 'Berhasil'), res.message || t('import_success', 'Import berhasil'));
                refreshData();
            },
            error: function (xhr) { notifikasi('error', t('generic_failed', 'Gagal'), formatAjaxErrors(xhr, t('import_failed', 'Import gagal.'))); }
        });
    });

    $(document).on('click', '.btn_delete_barang', function () {
        var id = $(this).data('id');
        var name = $(this).data('index');
        Swal.fire({
            title: t('delete_barang_title', 'Hapus Barang?'),
            text: t('delete_barang_text', 'Barang ":name" akan dihapus permanen.').replace(':name', name),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280',
            confirmButtonText: t('confirm_delete', 'Ya, Hapus'),
            cancelButtonText: t('cancel', 'Batal')
        }).then(function (result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: '/barang/' + id, method: 'delete', data: { _token: token },
                    success: function (res) {
                        if (typeof showToast === 'function') {
                            showToast(res.message || t('success', 'Berhasil'));
                        }
                        refreshData();
                    },
                    error: function (xhr) { notifikasi('error', t('generic_failed', 'Gagal'), formatAjaxErrors(xhr, t('delete_failed', 'Gagal menghapus.'))); }
                });
            }
        });
    });

    $(document).on('click', '.btn_withdraw_barang', function () {
        var id = $(this).data('id');
        var name = $(this).data('index');
        Swal.fire({
            title: t('withdraw_confirm_title', 'Tarik barang kembali?'),
            text: t('withdraw_confirm_text', 'Barang akan ditandai ditarik kembali dan tidak dapat dimuat ke container.').replace(':name', name),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#6b7280',
            confirmButtonText: t('withdraw', 'Ditarik Kembali'),
            cancelButtonText: t('cancel', 'Batal')
        }).then(function (result) {
            if (result.isConfirmed) {
                postBarangStatusUpdate(id, 'ditarik_kembali', t('barang_withdrawn', 'Barang berhasil ditarik kembali.'));
            }
        });
    });

    $(document).on('click', '.btn_reactivate_barang', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: t('reactivate_confirm_title', 'Aktifkan kembali ke Stuffing?'),
            text: t('reactivate_confirm_text', 'Barang akan kembali ke status Stuffing dan dapat diproses lagi.'),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#4f46e5',
            cancelButtonColor: '#6b7280',
            confirmButtonText: t('reactivate', 'Aktifkan Kembali'),
            cancelButtonText: t('cancel', 'Batal')
        }).then(function (result) {
            if (result.isConfirmed) {
                postBarangStatusUpdate(id, 'stuffing', t('barang_reactivated', 'Barang berhasil diaktifkan kembali ke Stuffing.'));
            }
        });
    });

    function inisialisasi() {
        table = $('#barangTable').DataTable({
            dom: 'rt<"dt-bottom"lip>',
            searching: false,
            ordering: true,
            order: [],
            pageLength: 25,
            scrollX: false,
            autoWidth: false,
            columns: [
                { data: 'tanggal_masuk', className: 'barang-col-meta' },
                { data: 'marking', className: 'barang-col-marking' },
                { data: 'barang_summary', className: 'barang-col-barang' },
                { data: 'notes', className: 'barang-col-notes' },
                { data: 'packing_name', className: 'barang-col-packing' },
                { data: 'partai_display', className: 'barang-col-partai' },
                { data: 'resi_display', className: 'barang-col-resi' },
                { data: 'ctns', className: 'barang-col-volume' },
                { data: 'status', className: 'barang-col-status' },
                { data: 'action', className: 'barang-col-actions text-center', orderable: false }
            ],
            language: { sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data', infoEmpty: 'Tidak ada data', emptyTable: 'Tidak ada data tersedia',
                paginate: { next: '&raquo;', previous: '&laquo;' } }
        });
        table.on('draw.dt', function () {
            if (window.initBsTooltips) {
                window.initBsTooltips('#barangTable');
            }
        });
        bindTableStatePersistence();
    }

    function refreshData(done) {
        if (restoringListState) {
            if (typeof done === 'function') {
                done();
            }
            return;
        }

        if (listRequest && typeof listRequest.abort === 'function') {
            try {
                listRequest.abort();
            } catch (e) {
                /* ignore */
            }
        }

        var requestSeq = ++listRequestSeq;
        var requestParams = filterParams();

        listRequest = $.ajax({
            url: '/barang/data', method: 'get', data: requestParams,
            success: function (e) {
                if (requestSeq !== listRequestSeq) {
                    return;
                }
                if (!Array.isArray(e)) {
                    console.error('Gagal load barang: respons tidak valid');
                    if (typeof done === 'function') {
                        done();
                    }
                    return;
                }
                table.clear();
                for (var i = 0; i < e.length; i++) {
                    var row = e[i];
                    var raw_index = row.no_index;

                    var metaHtml = '<div class="barang-meta-stack">';
                    metaHtml += row.tanggal_masuk_display
                        ? '<div class="barang-meta-date">' + escHtml(row.tanggal_masuk_display) + '</div>'
                        : '<div class="barang-meta-date text-muted">-</div>';
                    metaHtml += '<div class="barang-meta-index">' + escHtml(raw_index || '-');
                    if (row.has_invoice) {
                        metaHtml += ' <i class="bi bi-file-earmark-check text-success" style="font-size:.7rem" title="Invoice attached"></i>';
                    }
                    metaHtml += '</div>';
                    var gudangText = row.gudang_name == null ? '' : String(row.gudang_name).trim();
                    if (gudangText) {
                        metaHtml += '<div class="barang-meta-gudang">' + escHtml(gudangText) + '</div>';
                    }
                    var cityText = row.branch_city == null ? '' : String(row.branch_city).trim();
                    if (cityText) {
                        metaHtml += '<div class="barang-meta-city">' + escHtml(cityText) + '</div>';
                    }
                    metaHtml += '</div>';
                    row.tanggal_masuk = metaHtml;
                    row.no_index = '';

                    var markingHtml = '<div class="mark-cell">';
                    markingHtml += '<div class="mark-code">' + escHtml(row.marking || '—') + '</div>';
                    if (row.customer_name) {
                        markingHtml += '<div class="mark-meta">' + escHtml(row.customer_name) + '</div>';
                    }
                    if (row.marketing_name) {
                        markingHtml += '<div class="mark-meta"><i class="bi bi-person-badge me-1"></i>' + escHtml(row.marketing_name) + '</div>';
                    }
                    markingHtml += '</div>';
                    row.marking = markingHtml;

                    row.barang_summary = '<span class="small barang-cell-wrap">' + escHtml(row.barang_summary || '-') + '</span>';
                    var notesRaw = row.notes == null ? '' : String(row.notes).trim();
                    row.notes = notesRaw
                        ? '<span class="small barang-cell-wrap">' + escHtml(notesRaw) + '</span>'
                        : '<span class="text-muted">-</span>';
                    row.packing_name = '<span class="small">' + cellMutedDash(row.packing_name) + '</span>';
                    if (row.partai_display) {
                        row.partai_display = '<span class="small fw-600" style="color:var(--text-primary)">' + escHtml(row.partai_display) + '</span>';
                    } else {
                        row.partai_display = '<span class="small text-muted">' + escHtml(t('partai_not_loaded', 'Belum dimuat')) + '</span>';
                    }
                    row.resi_display = formatResiStack(row.resi_lines);
                    row.ctns = formatVolumeStack(row);
                    row.status = '<span class="badge-glass ' + (row.status_badge || '') + '">' + escHtml(statusLabel(row.raw_status)) + '</span>';

                    var actionBtns = '';
                    if (typeof _canEditBarang !== 'undefined' && _canEditBarang) {
                        actionBtns += '<a class="btn btn-glass btn-sm" href="' + barangEditUrl(row.id) + '"' + tipAttrs('tooltip_edit', 'Ubah barang') + '><i class="bi bi-pencil"></i></a>';
                    }
                    if (typeof _canDownloadInvoice !== 'undefined' && _canDownloadInvoice && row.invoice_download_url) {
                        actionBtns += '<a class="btn btn-glass btn-sm" href="' + row.invoice_download_url + '" target="_blank" rel="noopener"' + tipAttrs('tooltip_download_invoice', 'Unduh invoice PDF') + '><i class="bi bi-cash-stack" style="color:#16a34a"></i></a>';
                    }
                    var terimaPdfUrl = row.terima_barang_pdf_url;
                    if (typeof _canExportProducts !== 'undefined' && _canExportProducts && terimaPdfUrl) {
                        actionBtns += '<a class="btn btn-glass btn-sm" href="' + escAttr(terimaPdfUrl) + '" target="_blank" rel="noopener"' + tipAttrs('tooltip_export_terima_barang', 'Export PDF Terima Barang') + '><i class="bi bi-file-earmark-pdf" style="color:#ef4444"></i></a>';
                    }
                    if (typeof _canDeleteBarang !== 'undefined' && _canDeleteBarang) {
                        actionBtns += '<a class="btn btn-glass btn-sm text-danger btn_delete_barang" href="javascript:void(0);" data-id="' + row.id + '" data-index="' + escAttr(raw_index) + '"' + tipAttrs('tooltip_delete', 'Hapus barang') + '><i class="bi bi-trash"></i></a>';
                    }
                    if (typeof _canWithdrawBarang !== 'undefined' && _canWithdrawBarang && row.can_withdraw) {
                        actionBtns += '<a class="btn btn-glass btn-sm text-danger btn_withdraw_barang" href="javascript:void(0);" data-id="' + row.id + '" data-index="' + escAttr(raw_index) + '"' + tipAttrs('withdraw', 'Ditarik Kembali') + '><i class="bi bi-arrow-return-left"></i></a>';
                    }
                    if (typeof _canWithdrawBarang !== 'undefined' && _canWithdrawBarang && row.can_reactivate) {
                        actionBtns += '<a class="btn btn-glass btn-sm btn_reactivate_barang" href="javascript:void(0);" data-id="' + row.id + '" data-index="' + escAttr(raw_index) + '"' + tipAttrs('reactivate', 'Aktifkan Kembali') + '><i class="bi bi-arrow-counterclockwise"></i></a>';
                    }
                    row.action = '<div class="d-flex gap-2 justify-content-center align-items-center">' + actionBtns + '</div>';
                }
                table.order([]);
                table.rows.add(e).draw(false);
                renderActiveFilters();
                if (typeof done !== 'function') {
                    captureBarangListState();
                }
                if (typeof done === 'function') {
                    done();
                }
            },
            error: function (err) {
                if (requestSeq !== listRequestSeq) {
                    return;
                }
                if (err && err.statusText === 'abort') {
                    return;
                }
                console.error('Gagal load barang:', err);
                if (typeof done === 'function') {
                    done();
                }
            },
            complete: function () {
                if (requestSeq === listRequestSeq) {
                    listRequest = null;
                }
            },
        });
    }
});

