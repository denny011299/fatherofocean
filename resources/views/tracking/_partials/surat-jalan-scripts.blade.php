<script>
@php
    $sjBuktiI18n = [
        'bukti_modal_title' => __('delivery_trip.bukti_modal_title'),
        'bukti_existing_photos' => __('delivery_trip.bukti_existing_photos'),
        'bukti_existing_videos' => __('delivery_trip.bukti_existing_videos'),
        'bukti_upload_new' => __('delivery_trip.bukti_upload_new'),
        'bukti_photos_label' => __('delivery_trip.bukti_photos_label'),
        'bukti_videos_label' => __('delivery_trip.bukti_videos_label'),
        'bukti_photos_hint' => __('delivery_trip.bukti_photos_hint'),
        'bukti_videos_hint' => __('delivery_trip.bukti_videos_hint'),
        'bukti_save_departure' => __('delivery_trip.bukti_save_departure'),
        'bukti_save_arrival' => __('delivery_trip.bukti_save_arrival'),
        'bukti_require_both' => __('delivery_trip.bukti_require_both'),
        'bukti_uploading' => __('delivery_trip.bukti_uploading'),
        'departure_section' => __('delivery_trip.departure_section'),
        'arrival_section' => __('delivery_trip.arrival_section'),
        'no_photo_yet' => __('delivery_trip.photos').': belum ada',
        'no_video_yet' => __('delivery_trip.videos').': belum ada',
        'bukti_need_truck' => __('delivery_trip.bukti_need_truck'),
        'bukti_departure_locked' => __('delivery_trip.bukti_departure_locked'),
        'bukti_arrival_locked' => __('delivery_trip.bukti_arrival_locked'),
        'bukti_no_permission' => __('delivery_trip.bukti_no_permission'),
    ];
    $sjSplitI18n = [
        'title' => __('delivery_trip.split_delivery_title'),
        'hint_index' => __('delivery_trip.split_delivery_hint_index'),
        'hint_coly' => __('delivery_trip.split_delivery_hint_coly'),
        'toggle' => __('delivery_trip.split_toggle_btn'),
        'address_row' => __('delivery_trip.split_address_row', ['num' => ':num']),
        'add' => __('delivery_trip.split_add_address'),
        'remove' => __('delivery_trip.split_remove'),
        'coly' => __('delivery_trip.split_coly_label'),
        'exceeds' => __('delivery_trip.split_coly_exceeds_total', ['sum' => ':sum', 'total' => ':total']),
        'positive' => __('delivery_trip.split_coly_positive'),
        'total_required' => __('delivery_trip.split_total_coly_required'),
        'index_assign_title' => __('delivery_trip.split_index_assign_title'),
        'index_assign_col' => __('delivery_trip.split_index_assign_col'),
        'index_col_index' => __('delivery_trip.split_index_col_index'),
        'index_min_addresses' => __('delivery_trip.split_index_min_addresses'),
        'index_min_assignments' => __('delivery_trip.split_index_min_assignments'),
        'index_unassigned' => __('delivery_trip.split_index_unassigned'),
        'toggle_intro' => __('delivery_trip.split_toggle_intro'),
        'save_btn' => __('delivery_trip.split_save_btn'),
        'save_btn_default' => __('delivery_trip.split_save_btn_default'),
    ];
@endphp
window.SJ_BUKTI_I18N = @json($sjBuktiI18n);
window.SJ_SPLIT_I18N = @json($sjSplitI18n);
(function () {
    function t(key) {
        return (window.SJ_BUKTI_I18N && window.SJ_BUKTI_I18N[key]) || key;
    }

    function splitT(key) {
        return (window.SJ_SPLIT_I18N && window.SJ_SPLIT_I18N[key]) || key;
    }

    function optionalTruckValue(value) {
        var normalized = String(value == null ? '' : value).trim();
        return (normalized === '-' || normalized === '.') ? '' : normalized;
    }

    function displayTruckValue(value) {
        var normalized = optionalTruckValue(value);
        return normalized !== '' ? normalized : '-';
    }

    function pdfDisabledTitle(marking) {
        if (marking.can_download_pdf || marking.pdf_url) {
            return 'Download PDF';
        }
        return 'PDF belum tersedia';
    }

    function parseSplitColy(value) {
        var digits = String(value || '').replace(/[^\d]/g, '');
        return digits === '' ? 0 : parseInt(digits, 10);
    }

    function splitUsesIndexMode(splits) {
        if (!Array.isArray(splits)) {
            return false;
        }
        for (var i = 0; i < splits.length; i++) {
            var ids = splits[i].barang_ids;
            if (Array.isArray(ids) && ids.length) {
                return true;
            }
        }
        return false;
    }

    function findSplitGroupForBarang(splits, barangId) {
        if (!Array.isArray(splits)) {
            return '';
        }
        for (var g = 0; g < splits.length; g++) {
            var ids = splits[g].barang_ids;
            if (!Array.isArray(ids)) {
                continue;
            }
            for (var j = 0; j < ids.length; j++) {
                if (parseInt(ids[j], 10) === parseInt(barangId, 10)) {
                    return String(g);
                }
            }
        }
        return '';
    }

    function buildSplitRowHtml(uid, rowIndex, row, indexMode) {
        row = row || {};
        var rowId = uid + 'split_row_' + rowIndex;
        var colyField = indexMode ? '' :
            '<div class="col-md-4"><label class="form-label">' + esc(splitT('coly')) + '</label>' +
            '<input type="number" min="1" step="1" class="form-control sj-editable-input sj-split-coly" value="' +
            escAttr(row.coly > 0 ? String(row.coly) : '') + '"></div>';
        var receiverCols = indexMode ? 'col-md-6' : 'col-md-4';
        return '<div class="sj-split-row glass-solid p-2 mb-2" data-row-index="' + rowIndex + '" id="' + rowId + '">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<span class="small fw-600 text-muted-glass">' + esc(splitT('address_row').replace(':num', String(rowIndex + 1))) + '</span>' +
            '<button type="button" class="btn btn-sm btn-glass sj-split-remove" title="' + escAttr(splitT('remove')) + '">' +
            '<i class="bi bi-trash"></i></button></div>' +
            '<div class="row g-2">' +
            '<div class="col-12"><label class="form-label">Alamat</label>' +
            '<textarea class="form-control sj-editable-input sj-split-address" rows="2">' + esc(row.address || '') + '</textarea></div>' +
            '<div class="' + receiverCols + '"><label class="form-label">Nama Penerima</label>' +
            '<input type="text" class="form-control sj-editable-input sj-split-receiver" value="' + escAttr(row.receiver_name || '') + '"></div>' +
            '<div class="' + receiverCols + '"><label class="form-label">Telp Penerima</label>' +
            '<input type="text" class="form-control sj-editable-input sj-split-phone" value="' + escAttr(row.receiver_phone || '') + '"></div>' +
            colyField + '</div></div>';
    }

    function buildSplitAssignTableHtml(uid, shipments, existingSplits) {
        var html = '<div class="table-responsive mt-2"><table class="table-glass table-sm mb-0">' +
            '<thead><tr><th>' + esc(splitT('index_col_index')) + '</th><th>Nama</th><th>' + esc(splitT('index_assign_col')) + '</th></tr></thead><tbody>';
        shipments.forEach(function (s) {
            var selected = findSplitGroupForBarang(existingSplits, s.id);
            html += '<tr data-barang-id="' + escAttr(String(s.id)) + '">' +
                '<td class="fw-600">' + esc(s.no_index || '-') + '</td>' +
                '<td class="small">' + esc(s.nama_barang || s.marking || '-') + ' · ' + esc(String(s.ctns || 0)) + ' coly</td>' +
                '<td><select class="form-select form-select-sm sj-editable-input sj-split-assign" data-barang-id="' + escAttr(String(s.id)) + '">' +
                '<option value="">' + esc(splitT('index_unassigned')) + '</option></select></td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    function refreshSplitAssignOptions($container, uid) {
        var options = '<option value="">' + esc(splitT('index_unassigned')) + '</option>';
        $container.find('#' + uid + 'split_rows .sj-split-row').each(function (idx) {
            options += '<option value="' + idx + '">' + esc(splitT('address_row').replace(':num', String(idx + 1))) + '</option>';
        });
        $container.find('.sj-split-assign').each(function () {
            var $sel = $(this);
            var prev = $sel.val();
            $sel.html(options);
            if (prev !== '' && $container.find('#' + uid + 'split_rows .sj-split-row').length > parseInt(prev, 10)) {
                $sel.val(prev);
            }
        });
    }

    function collectSplitDeliveries($container, uid, indexMode) {
        if (indexMode) {
            var groupCount = $container.find('#' + uid + 'split_rows .sj-split-row').length;
            var groups = [];
            var g;
            for (g = 0; g < groupCount; g++) {
                groups.push({
                    address: '',
                    receiver_name: '',
                    receiver_phone: '',
                    coly: 0,
                    barang_ids: [],
                });
            }
            $container.find('#' + uid + 'split_rows .sj-split-row').each(function (idx) {
                var $row = $(this);
                groups[idx] = {
                    address: String($row.find('.sj-split-address').val() || '').trim(),
                    receiver_name: String($row.find('.sj-split-receiver').val() || '').trim(),
                    receiver_phone: String($row.find('.sj-split-phone').val() || '').trim(),
                    coly: 0,
                    barang_ids: groups[idx].barang_ids,
                };
            });
            $container.find('.sj-split-assign').each(function () {
                var groupIdx = parseInt($(this).val(), 10);
                var barangId = parseInt($(this).data('barang-id'), 10);
                if (isNaN(groupIdx) || groupIdx < 0 || isNaN(barangId) || barangId <= 0) {
                    return;
                }
                if (!groups[groupIdx]) {
                    return;
                }
                groups[groupIdx].barang_ids.push(barangId);
            });
            return groups.filter(function (r) {
                return r.address !== '' || r.receiver_name !== '' || r.receiver_phone !== '' || (r.barang_ids && r.barang_ids.length);
            });
        }

        var rows = [];
        $container.find('#' + uid + 'split_rows .sj-split-row').each(function () {
            var $row = $(this);
            rows.push({
                address: String($row.find('.sj-split-address').val() || '').trim(),
                receiver_name: String($row.find('.sj-split-receiver').val() || '').trim(),
                receiver_phone: String($row.find('.sj-split-phone').val() || '').trim(),
                coly: parseSplitColy($row.find('.sj-split-coly').val()),
            });
        });
        return rows.filter(function (r) {
            return r.address !== '' || r.receiver_name !== '' || r.receiver_phone !== '' || r.coly > 0;
        });
    }

    function validateSplitDeliveriesClient(splits, totalColyRaw, indexMode, shipmentCount) {
        if (!splits.length) {
            return null;
        }
        if (indexMode) {
            var addressCount = 0;
            var assignedGroups = {};
            for (var i = 0; i < splits.length; i++) {
                if ((splits[i].address || '').trim() !== '') {
                    addressCount++;
                }
                var ids = splits[i].barang_ids || [];
                if (ids.length) {
                    assignedGroups[i] = true;
                }
            }
            if (addressCount < 2) {
                return splitT('index_min_addresses');
            }
            if (Object.keys(assignedGroups).length < 2) {
                return splitT('index_min_assignments');
            }
            return null;
        }

        var maxTotal = parseSplitColy(totalColyRaw);
        if (maxTotal <= 0) {
            return splitT('total_required');
        }
        var sum = 0;
        for (var j = 0; j < splits.length; j++) {
            if ((splits[j].coly || 0) <= 0) {
                return splitT('positive');
            }
            sum += splits[j].coly;
        }
        if (sum > maxTotal) {
            return splitT('exceeds').replace(':sum', String(sum)).replace(':total', String(maxTotal));
        }
        return null;
    }
    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;');
    }

    function truncateCbm(value) {
        if (typeof window.formatCbm === 'function') {
            return window.formatCbm(value);
        }
        if (typeof window.truncateCbm === 'function') {
            return window.truncateCbm(value).toFixed(3);
        }
        return Number(value || 0).toFixed(3);
    }

    function renderMediaList(items, type) {
        if (!items || !items.length) {
            return '<div class="small text-muted-glass">' + esc(type === 'photo' ? t('no_photo_yet') : t('no_video_yet')) + '</div>';
        }
        if (type === 'photo') {
            var html = '<div class="d-flex flex-wrap gap-2 mb-2">';
            items.forEach(function (media) {
                html += '<a href="' + escAttr(media.url) + '" target="_blank" rel="noopener">'
                    + '<img src="' + escAttr(media.url) + '" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px">'
                    + '</a>';
            });
            return html + '</div>';
        }
        var links = '<div class="d-flex flex-column gap-1 mb-2">';
        items.forEach(function (media) {
            links += '<a href="' + escAttr(media.url) + '" target="_blank" class="btn btn-sm btn-glass">'
                + esc(media.original_name || 'Video') + '</a>';
        });
        return links + '</div>';
    }

    function cacheMarkings(state, payload) {
        state.markingsByKey = {};
        (payload.markings || []).forEach(function (m) {
            if (m && m.key) {
                state.markingsByKey[m.key] = m;
            }
        });
    }

    function renderMarkings($root, payload, state) {
        const markings = payload.markings || [];
        const $body = $root.find('.sj-markings-body');
        const $count = $root.find('.sj-markings-count');

        cacheMarkings(state, payload);
        $count.text(String(markings.length));

        if (!markings.length) {
            $body.html('<tr><td colspan="8" class="text-center text-muted-glass py-3">Tidak ada marking.</td></tr>');
            return;
        }

        const html = markings.map(function (m) {
            const canPdf = !!(m.can_download_pdf || m.pdf_url);
            const pdfTitle = pdfDisabledTitle(m);
            const shipmentHint = Number(m.shipments_count || 0) > 1
                ? '<div class="small text-muted-glass">' + m.shipments_count + ' kiriman digabung</div>'
                : '';
            const detail = (m.no_indexes || '').length > 48
                ? '<div class="small text-muted-glass" title="' + escAttr(m.no_indexes) + '">' + esc(m.no_indexes.substring(0, 48)) + '…</div>'
                : (m.no_indexes ? '<div class="small text-muted-glass">' + esc(m.no_indexes) + '</div>' : '');

            return '<tr data-marking-key="' + escAttr(m.key) + '">' +
                '<td class="fw-700 text-accent">' + esc(String(m.index)) + '<div class="small text-muted-glass">/' + esc(String(m.total)) + '</div></td>' +
                '<td><span class="badge-glass marking-badge" title="' + escAttr(m.code) + '">' + esc(m.code) + '</span>' + shipmentHint + detail + '</td>' +
                '<td class="small fw-600">' + esc(displayTruckValue(m.truck_plate)) + '</td>' +
                '<td class="small text-truncate" style="max-width:100px" title="' + escAttr(displayTruckValue(m.driver_name)) + '">' + esc(displayTruckValue(m.driver_name)) + '</td>' +
                '<td>' + Number(m.total_ctns || 0) + '</td>' +
                '<td class="text-accent fw-600">' + truncateCbm(m.total_cbm) + '</td>' +
                '<td><span class="badge-glass ' + esc(m.status_badge || 'badge-status-pending') + '">' + esc(m.status_label || '') + '</span></td>' +
                '<td class="sj-actions-cell">' +
                '<div class="sj-action-group">' +
                '<button type="button" class="btn btn-sm btn-glass sj-btn-edit-marking" data-key="' + escAttr(m.key) + '" title="Edit surat jalan"><i class="bi bi-pencil-square"></i></button>' +
                '<button type="button" class="btn btn-sm btn-glass sj-btn-bukti-marking" data-key="' + escAttr(m.key) + '" title="Bukti pengiriman"><i class="bi bi-camera-video"></i></button>' +
                (state.canBlankTemplate && state.blankTemplateUrl
                    ? '<a href="' + escAttr(state.blankTemplateUrl + (state.blankTemplateUrl.indexOf('?') >= 0 ? '&' : '?') + 'marking=' + encodeURIComponent(m.key)) + '" target="_blank" rel="noopener" class="btn btn-sm btn-glass" title="' + escAttr(state.blankTemplateLabel || 'Template kosong') + '"><i class="bi bi-file-earmark"></i></a>'
                    : '') +
                (canPdf
                    ? '<a href="' + escAttr(m.pdf_url) + '" target="_blank" rel="noopener" class="btn btn-sm btn-accent" title="' + escAttr(pdfTitle) + '"><i class="bi bi-file-earmark-pdf"></i></a>'
                    : '<span class="btn btn-sm btn-glass disabled opacity-50" title="' + escAttr(pdfTitle) + '"><i class="bi bi-file-earmark-pdf"></i></span>') +
                '</div></td></tr>';
        }).join('');

        $body.html(html);
    }

    window.SuratJalanUi = {
        reset: function ($root) {
            $root.find('.sj-trip-no').text('Draft');
            $root.find('.sj-markings-body').html('<tr><td colspan="8" class="text-center text-muted-glass py-3">Memuat...</td></tr>');
            $root.find('.sj-open-full-page').addClass('d-none').attr('href', '#');
            $root.find('.sj-download-blank-template').addClass('d-none').attr('href', '#');
            $root.find('.sj-download-rekapan-sj').addClass('d-none').attr('href', '#');
        },

        render: function ($root, payload, state) {
            if (!payload) {
                return;
            }

            state.currentTripId = payload.trip?.id || null;
            state.currentContainerId = payload.container?.id || state.currentContainerId;
            state.blankTemplateUrl = state.canBlankTemplate
                ? (payload.blank_template_url || state.blankTemplateUrl || '')
                : '';
            state.blankTemplateLabel = state.blankTemplateLabel || 'Template kosong';

            $root.find('.sj-container-id').val(payload.container?.id || '');
            $root.find('.sj-trip-id').val(payload.trip?.id || '');
            $root.find('.sj-trip-no').text(payload.trip?.trip_no || 'Surat Jalan');

            renderMarkings($root, payload, state);

            if (state.canBlankTemplate && payload.blank_template_url) {
                $root.find('.sj-download-blank-template')
                    .removeClass('d-none')
                    .attr('href', payload.blank_template_url);
            } else {
                $root.find('.sj-download-blank-template').addClass('d-none');
            }

            if (payload.rekapan_url) {
                $root.find('.sj-download-rekapan-sj')
                    .removeClass('d-none')
                    .attr('href', payload.rekapan_url);
            } else {
                $root.find('.sj-download-rekapan-sj').addClass('d-none');
            }

            if (payload.view_url && !state.isStandalonePage) {
                $root.find('.sj-open-full-page').removeClass('d-none').attr('href', payload.view_url);
            } else {
                $root.find('.sj-open-full-page').addClass('d-none');
            }

            if (typeof state.onRendered === 'function') {
                state.onRendered(payload);
            }
        },

        load: function ($root, containerId, state) {
            $.get(state.summaryUrl.replace('__CONTAINER_ID__', String(containerId)))
                .done(function (res) {
                    window.SuratJalanUi.render($root, res, state);
                })
                .fail(function (xhr) {
                    showToast(xhr.responseJSON?.message || 'Gagal memuat surat jalan.', 'error');
                });
        },

        bind: function ($root, state) {
            $root.on('click', '.sj-btn-edit-marking', function () {
                if (!state.currentContainerId) {
                    return;
                }
                var key = String($(this).data('key') || '');
                var marking = state.markingsByKey && state.markingsByKey[key];
                if (!marking) {
                    showToast('Data marking tidak ditemukan. Muat ulang halaman.', 'error');
                    return;
                }
                openMarkingEditor($root, state, marking);
            });

            $root.on('click', '.sj-btn-bukti-marking', function () {
                if (!state.currentContainerId) {
                    return;
                }
                var key = String($(this).data('key') || '');
                var marking = state.markingsByKey && state.markingsByKey[key];
                if (!marking) {
                    showToast('Data marking tidak ditemukan. Muat ulang halaman.', 'error');
                    return;
                }
                openMarkingBukti($root, state, marking);
            });
        },
    };

    function sjEditorContainer() {
        return $(Swal.getHtmlContainer());
    }

    /** Lepas semua blokir Bootstrap modal agar input SweetAlert bisa diketik. */
    function sjUnlockModalFocus() {
        $(document).off('focusin.modal focusin.bs.focustrap keydown.tab.bs.focustrap');
        var modalEl = document.getElementById('deliveryTripModal');
        if (modalEl) {
            var inst = typeof bootstrap !== 'undefined' ? bootstrap.Modal.getInstance(modalEl) : null;
            if (inst && inst._focustrap && inst._focustrap._isActive) {
                inst._focustrap.deactivate();
            }
        }
    }

    function sjRestoreModalFocus() {
        var modalEl = document.getElementById('deliveryTripModal');
        if (modalEl && modalEl.classList.contains('show')) {
            var inst = typeof bootstrap !== 'undefined' ? bootstrap.Modal.getInstance(modalEl) : null;
            if (inst && inst._focustrap && !inst._focustrap._isActive) {
                inst._focustrap.activate();
            }
        }
    }

    function sjEnableEditorFields($container) {
        if (!$container || !$container.length) {
            return;
        }
        $container.find('input, textarea, select').each(function () {
            var el = this;
            el.removeAttribute('readonly');
            el.removeAttribute('disabled');
            el.removeAttribute('inert');
            el.readOnly = false;
            el.disabled = false;
            el.setAttribute('aria-disabled', 'false');
            el.style.pointerEvents = 'auto';
            el.style.opacity = '1';
            if (el.classList) {
                el.classList.remove('disabled');
            }
        });
        $container.css({ 'pointer-events': 'auto', 'user-select': 'text' });
    }

    function sjBindEditorFieldGuard($container) {
        var handler = function () {
            sjUnlockModalFocus();
            sjEnableEditorFields($container);
        };
        $container.on('mousedown.sjEdit focusin.sjEdit click.sjEdit touchstart.sjEdit keydown.sjEdit', 'input, textarea, select', handler);
    }

    function openMarkingEditor($root, state, marking) {
        var uid = 'sj_' + Date.now() + '_';
        var summary = marking.summary || {};
        var saved = !!marking.has_saved_summary;
        function sv(summaryKey, markingKey) {
            var fromSummary = summary[summaryKey];
            if (fromSummary != null && String(fromSummary).trim() !== '') {
                return String(fromSummary);
            }
            if (markingKey) {
                var fromMarking = marking[markingKey];
                if (fromMarking != null && String(fromMarking).trim() !== '') {
                    return String(fromMarking);
                }
            }
            return '';
        }
        function readEditorTruckField($input, summaryKey, markingKey) {
            var fromInput = optionalTruckValue($input.val());
            if (fromInput) {
                return fromInput;
            }
            return optionalTruckValue(sv(summaryKey, markingKey));
        }
        function inp(id, val) {
            return '<input type="text" id="' + uid + id + '" name="' + uid + id + '" class="form-control sj-editable-input" autocomplete="off" spellcheck="false" data-lpignore="true" value="' + escAttr(val) + '">';
        }
        function txt(id, val, rows) {
            return '<textarea id="' + uid + id + '" name="' + uid + id + '" class="form-control sj-editable-input" autocomplete="off" spellcheck="false" rows="' + (rows || 2) + '">' + esc(val) + '</textarea>';
        }
        var existingSplits = Array.isArray(summary.split_deliveries) ? summary.split_deliveries : [];
        var shipments = Array.isArray(marking.shipments) ? marking.shipments : [];
        var splitIndexMode = shipments.length >= 2 && (splitUsesIndexMode(existingSplits) || existingSplits.length === 0);
        var splitPanelOpen = existingSplits.length > 0;
        var splitRowsHtml = '';
        if (splitPanelOpen) {
            existingSplits.forEach(function (row, idx) {
                splitRowsHtml += buildSplitRowHtml(uid, idx, row, splitIndexMode);
            });
        }
        var splitPanelHint = splitIndexMode ? splitT('hint_index') : splitT('hint_coly');
        var splitAssignHtml = splitIndexMode
            ? '<h6 class="fw-700 mb-2 mt-2"><i class="bi bi-signpost-split me-1 text-accent"></i>' + esc(splitT('index_assign_title')) + '</h6>' +
              buildSplitAssignTableHtml(uid, shipments, existingSplits)
            : '';
        var html = '' +
            '<div class="text-start sj-editor-form" autocomplete="off">' +
            '<h6 class="fw-700 mb-2"><i class="bi bi-truck me-1 text-accent"></i>Data Truck</h6>' +
            '<div class="row g-2 mb-3">' +
            '<div class="col-md-3"><label class="form-label">No. Polisi</label>' + inp('truck_plate', optionalTruckValue(sv('truck_plate', 'truck_plate'))) + '</div>' +
            '<div class="col-md-3"><label class="form-label">Nama Supir</label>' + inp('driver_name', optionalTruckValue(sv('driver_name', 'driver_name'))) + '</div>' +
            '<div class="col-md-3"><label class="form-label">No. HP Supir</label>' + inp('driver_phone', optionalTruckValue(sv('driver_phone', 'driver_phone'))) + '</div>' +
            '<div class="col-md-3"><label class="form-label">Tujuan</label>' + inp('destination', optionalTruckValue(sv('destination', 'destination'))) + '</div>' +
            '<div class="col-12"><label class="form-label">Catatan Truck</label>' + txt('trip_notes', sv('trip_notes', 'trip_notes'), 2) + '</div>' +
            '</div>' +
            '<h6 class="fw-700 mb-2"><i class="bi bi-file-earmark-text me-1 text-accent"></i>Isi Surat Jalan</h6>' +
            '<p class="small text-muted-glass mb-2">Semua field bisa diedit — ketik ulang alamat/customer jika belum sesuai.</p>' +
            '<div class="row g-2">' +
            '<div class="col-md-4"><label class="form-label">Nomor Marking *</label>' + inp('marking_code', saved ? sv('marking_code') : (summary.marking_code || marking.code || '')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Customer</label>' + inp('customer', sv('customer', 'customer')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Telp</label>' + inp('telp', sv('telp', 'telp')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Loading Date</label><input type="date" id="' + uid + 'loading_date" name="' + uid + 'loading_date" class="form-control sj-editable-input" autocomplete="off" value="' + escAttr(sv('loading_date')) + '"></div>' +
            '<div class="col-md-8"><label class="form-label">Alamat</label>' + txt('alamat', sv('alamat', 'alamat'), 3) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Nama Barang</label>' + inp('nama_barang', sv('nama_barang')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Total Coly</label>' + inp('total_coly', saved ? sv('total_coly') : (summary.total_coly || String(marking.total_ctns || ''))) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Partai</label>' + inp('partai', sv('partai')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Nama Penerima</label>' + inp('receiver_name', sv('nama_penerima')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">No. Telp Penerima</label>' + inp('receiver_phone', sv('no_telp_penerima')) + '</div>' +
            '<div class="col-md-4"><label class="form-label">Tanggal Terima</label><input type="date" id="' + uid + 'received_date" name="' + uid + 'received_date" class="form-control sj-editable-input" autocomplete="off" value="' + escAttr(sv('tanggal_terima')) + '"></div>' +
            '<div class="col-12"><label class="form-label">Catatan SJ</label>' + txt('print_notes', sv('notes'), 2) + '</div>' +
            '</div>' +
            '<div class="border-top pt-3 mt-3">' +
            '<p class="small text-muted-glass mb-2"><i class="bi bi-signpost-2 me-1"></i>' + esc(splitT('toggle_intro')) + '</p>' +
            '<button type="button" class="btn btn-sm btn-glass sj-split-toggle" id="' + uid + 'split_toggle">' +
            '<i class="bi bi-file-earmark-pdf me-1"></i>' + esc(splitT('toggle')) +
            '</button>' +
            '<div class="sj-split-panel mt-2 ' + (splitPanelOpen ? '' : 'd-none') + '" id="' + uid + 'split_panel">' +
            '<h6 class="fw-700 mb-2"><i class="bi bi-file-earmark-pdf me-1 text-accent"></i>' + esc(splitT('title')) + '</h6>' +
            '<p class="small text-muted-glass mb-2">' + esc(splitPanelHint) + '</p>' +
            '<div id="' + uid + 'split_rows">' + splitRowsHtml + '</div>' +
            '<div id="' + uid + 'split_assign">' + splitAssignHtml + '</div>' +
            '<button type="button" class="btn btn-sm btn-glass sj-split-add" id="' + uid + 'split_add">' +
            '<i class="bi bi-plus-lg me-1"></i>' + esc(splitT('add')) +
            '</button></div></div></div>';

        var sjGuardInterval = null;
        var splitPanelTouched = splitPanelOpen;

        Swal.fire({
            title: 'Edit — ' + (marking.code || marking.key),
            html: html,
            width: 920,
            showCancelButton: true,
            confirmButtonText: splitPanelTouched ? splitT('save_btn') : splitT('save_btn_default'),
            cancelButtonText: 'Batal',
            focusConfirm: false,
            heightAuto: false,
            didOpen: function () {
                var swalContainer = Swal.getContainer();
                if (swalContainer) {
                    swalContainer.classList.add('sj-editor-open');
                    swalContainer.style.zIndex = '2000';
                    swalContainer.style.pointerEvents = 'auto';
                }
                var popup = Swal.getPopup();
                if (popup) {
                    popup.style.pointerEvents = 'auto';
                }
                sjUnlockModalFocus();
                var $c = sjEditorContainer();
                sjEnableEditorFields($c);
                sjBindEditorFieldGuard($c);
                sjGuardInterval = window.setInterval(function () {
                    sjUnlockModalFocus();
                    sjEnableEditorFields($c);
                }, 200);

                var $panel = $c.find('#' + uid + 'split_panel');
                var $rows = $c.find('#' + uid + 'split_rows');
                var nextSplitIndex = existingSplits.length;

                function updateSplitSaveButtonLabel() {
                    var splitActive = splitPanelTouched || !$panel.hasClass('d-none');
                    var $confirm = $(Swal.getConfirmButton());
                    if ($confirm.length) {
                        $confirm.text(splitActive ? splitT('save_btn') : splitT('save_btn_default'));
                    }
                }

                function appendSplitRow(row) {
                    $rows.append(buildSplitRowHtml(uid, nextSplitIndex, row || {}, splitIndexMode));
                    nextSplitIndex += 1;
                    sjEnableEditorFields($rows);
                    if (splitIndexMode) {
                        refreshSplitAssignOptions($c, uid);
                    }
                }

                $c.find('#' + uid + 'split_toggle').on('click', function () {
                    splitPanelTouched = true;
                    $panel.removeClass('d-none');
                    updateSplitSaveButtonLabel();
                    if (!$rows.children('.sj-split-row').length) {
                        if (splitIndexMode) {
                            appendSplitRow({});
                            appendSplitRow({});
                        } else {
                            appendSplitRow({});
                        }
                    }
                    if (splitIndexMode) {
                        refreshSplitAssignOptions($c, uid);
                    }
                });

                $c.on('click', '.sj-split-add', function () {
                    splitPanelTouched = true;
                    appendSplitRow({});
                    updateSplitSaveButtonLabel();
                });

                $c.on('click', '.sj-split-remove', function () {
                    splitPanelTouched = true;
                    $(this).closest('.sj-split-row').remove();
                    if (splitIndexMode) {
                        refreshSplitAssignOptions($c, uid);
                    }
                    updateSplitSaveButtonLabel();
                });

                updateSplitSaveButtonLabel();

                if (splitIndexMode && splitPanelOpen) {
                    refreshSplitAssignOptions($c, uid);
                    $c.find('.sj-split-assign').each(function () {
                        var barangId = parseInt($(this).data('barang-id'), 10);
                        var group = findSplitGroupForBarang(existingSplits, barangId);
                        if (group !== '') {
                            $(this).val(group);
                        }
                    });
                }

                $c.find('#' + uid + 'truck_plate').trigger('focus');
            },
            willClose: function () {
                var swalContainer = Swal.getContainer();
                if (swalContainer) {
                    swalContainer.classList.remove('sj-editor-open');
                }
                if (sjGuardInterval) {
                    window.clearInterval(sjGuardInterval);
                    sjGuardInterval = null;
                }
                sjEditorContainer().off('.sjEdit');
                sjRestoreModalFocus();
            },
            preConfirm: function () {
                var $c = sjEditorContainer();
                var plate = readEditorTruckField($c.find('#' + uid + 'truck_plate'), 'truck_plate', 'truck_plate');
                var driver = readEditorTruckField($c.find('#' + uid + 'driver_name'), 'driver_name', 'driver_name');
                var markingCode = String($c.find('#' + uid + 'marking_code').val() || '').trim();
                if (!markingCode) {
                    Swal.showValidationMessage('Nomor marking wajib diisi.');
                    return false;
                }
                var payload = {
                    marking_key: marking.key,
                    truck_plate: plate,
                    driver_name: driver,
                    driver_phone: readEditorTruckField($c.find('#' + uid + 'driver_phone'), 'driver_phone', 'driver_phone'),
                    destination: readEditorTruckField($c.find('#' + uid + 'destination'), 'destination', 'destination'),
                    trip_notes: $c.find('#' + uid + 'trip_notes').val(),
                    marking_code: $c.find('#' + uid + 'marking_code').val(),
                    customer: $c.find('#' + uid + 'customer').val(),
                    telp: $c.find('#' + uid + 'telp').val(),
                    alamat: $c.find('#' + uid + 'alamat').val(),
                    loading_date: $c.find('#' + uid + 'loading_date').val(),
                    nama_barang: $c.find('#' + uid + 'nama_barang').val(),
                    total_coly: $c.find('#' + uid + 'total_coly').val(),
                    partai: $c.find('#' + uid + 'partai').val(),
                    receiver_name: $c.find('#' + uid + 'receiver_name').val(),
                    receiver_phone: $c.find('#' + uid + 'receiver_phone').val(),
                    received_date: $c.find('#' + uid + 'received_date').val(),
                    print_notes: $c.find('#' + uid + 'print_notes').val(),
                };
                if (splitPanelTouched) {
                    var splits = collectSplitDeliveries($c, uid, splitIndexMode);
                    var splitErr = validateSplitDeliveriesClient(
                        splits,
                        $c.find('#' + uid + 'total_coly').val(),
                        splitIndexMode,
                        shipments.length
                    );
                    if (splitErr) {
                        Swal.showValidationMessage(splitErr);
                        return false;
                    }
                    payload.split_deliveries = splits;
                }
                return payload;
            },
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) {
                return;
            }
            $.ajax({
                url: state.upsertUrl.replace('__CONTAINER_ID__', String(state.currentContainerId)),
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': state.csrf },
                data: result.value,
                success: function (res) {
                    showToast(res.message || 'Data pengiriman disimpan.', 'success');
                    if (res && res.data) {
                        window.SuratJalanUi.render($root, res.data, state);
                    } else if (state.currentContainerId) {
                        window.SuratJalanUi.load($root, state.currentContainerId, state);
                    }
                },
                error: function (xhr) {
                    showToast(xhr.responseJSON?.message || 'Gagal menyimpan.', 'error');
                },
            });
        });
    }

    function renderLocalPhotoPreview($container, files) {
        $container.empty();
        if (!files || !files.length) {
            return;
        }
        var html = '<div class="d-flex flex-wrap gap-2">';
        Array.prototype.forEach.call(files, function (file) {
            if (!file.type || file.type.indexOf('image/') !== 0) {
                return;
            }
            var url = URL.createObjectURL(file);
            html += '<div class="sj-bukti-preview-thumb">' +
                '<img src="' + escAttr(url) + '" alt="">' +
                '<span class="small text-truncate d-block" style="max-width:76px" title="' + escAttr(file.name) + '">' + esc(file.name) + '</span>' +
                '</div>';
        });
        $container.html(html + '</div>');
    }

    function renderLocalVideoPreview($container, files) {
        $container.empty();
        if (!files || !files.length) {
            return;
        }
        var html = '<div class="d-flex flex-column gap-1">';
        Array.prototype.forEach.call(files, function (file) {
            html += '<div class="sj-bukti-preview-video small">' +
                '<i class="bi bi-camera-video me-1 text-accent"></i>' +
                esc(file.name) + ' <span class="text-muted-glass">(' + formatFileSize(file.size) + ')</span>' +
                '</div>';
        });
        $container.html(html + '</div>');
    }

    function formatFileSize(bytes) {
        var n = Number(bytes) || 0;
        if (n < 1024) {
            return n + ' B';
        }
        if (n < 1048576) {
            return (n / 1024).toFixed(1) + ' KB';
        }
        return (n / 1048576).toFixed(1) + ' MB';
    }

    async function compressPhotoFiles(fileList) {
        if (typeof window.compressDeliveryTripPhotos === 'function') {
            try {
                return await window.compressDeliveryTripPhotos(fileList);
            } catch (err) {
                if (err && err.message === 'photo_too_large') {
                    showToast('Foto melebihi 10 MB. Pilih file lebih kecil.', 'error');
                    throw err;
                }
                return Array.prototype.slice.call(fileList || []);
            }
        }
        return Array.prototype.slice.call(fileList || []);
    }

    function buildBuktiPhaseCard(uid, phaseKey, title, accentClass, photos, videos, canUpload, blockReason) {
        var html = '<div class="sj-bukti-phase ' + accentClass + ' mb-3">' +
            '<div class="fw-700 mb-2"><i class="bi bi-' + (phaseKey === 'departure' ? 'box-arrow-right' : 'geo-alt-fill') + ' me-1"></i>' + esc(title) + '</div>' +
            '<div class="row g-3 mb-2">' +
            '<div class="col-md-6">' +
            '<div class="small fw-600 text-muted-glass mb-1"><i class="bi bi-image me-1"></i>' + esc(t('bukti_existing_photos')) + '</div>' +
            renderMediaList(photos, 'photo') +
            '</div>' +
            '<div class="col-md-6">' +
            '<div class="small fw-600 text-muted-glass mb-1"><i class="bi bi-camera-video me-1"></i>' + esc(t('bukti_existing_videos')) + '</div>' +
            renderMediaList(videos, 'video') +
            '</div></div>';

        if (canUpload) {
            html += '<div class="border-top pt-3 mt-2">' +
                '<div class="small fw-700 text-accent mb-2">' + esc(t('bukti_upload_new')) + '</div>' +
                '<div class="row g-3">' +
                '<div class="col-md-6">' +
                '<label class="form-label fw-600 mb-1" for="' + uid + '_photos">' +
                '<i class="bi bi-image text-accent me-1"></i>' + esc(t('bukti_photos_label')) + ' <span class="text-danger">*</span></label>' +
                '<input type="file" id="' + uid + '_photos" class="form-control form-control-sm sj-bukti-file" accept="image/jpeg,image/png,image/webp" multiple>' +
                '<div class="form-text">' + esc(t('bukti_photos_hint')) + '</div>' +
                '<div id="' + uid + '_photo_preview" class="sj-bukti-preview-area mt-2"></div>' +
                '</div>' +
                '<div class="col-md-6">' +
                '<label class="form-label fw-600 mb-1" for="' + uid + '_videos">' +
                '<i class="bi bi-camera-video text-accent me-1"></i>' + esc(t('bukti_videos_label')) + ' <span class="text-danger">*</span></label>' +
                '<input type="file" id="' + uid + '_videos" class="form-control form-control-sm sj-bukti-file" accept="video/mp4,video/quicktime,video/webm,video/*" multiple>' +
                '<div class="form-text">' + esc(t('bukti_videos_hint')) + '</div>' +
                '<div id="' + uid + '_video_preview" class="sj-bukti-preview-area mt-2"></div>' +
                '</div></div>' +
                '<button type="button" class="btn btn-accent btn-sm mt-3 sj-bukti-save-btn" data-phase="' + escAttr(phaseKey) + '" data-uid="' + escAttr(uid) + '">' +
                '<i class="bi bi-cloud-upload me-1"></i>' + esc(phaseKey === 'departure' ? t('bukti_save_departure') : t('bukti_save_arrival')) +
                '</button></div>';
        } else if (blockReason) {
            html += '<div class="border-top pt-3 mt-2">' +
                '<div class="small text-warning"><i class="bi bi-info-circle me-1"></i>' + esc(blockReason) + '</div></div>';
        }

        return html + '</div>';
    }

    function buktiBlockReason(marking, phase) {
        if (phase === 'departure') {
            if (marking.can_confirm_departure) {
                return '';
            }
            return t('bukti_departure_locked');
        }
        if (marking.can_confirm_arrival) {
            return '';
        }
        return t('bukti_arrival_locked');
    }

    function openMarkingBukti($root, state, marking) {
        var uidDep = 'sj_bukti_dep_' + Date.now();
        var uidArr = 'sj_bukti_arr_' + Date.now();
        var canDep = !!marking.can_confirm_departure;
        var canArr = !!marking.can_confirm_arrival;

        var html = '<div class="text-start sj-bukti-modal">' +
            '<p class="small text-muted-glass mb-3">' + esc(marking.sheet_label || marking.code) + '</p>' +
            buildBuktiPhaseCard(uidDep, 'departure', t('departure_section'), 'sj-bukti-departure',
                marking.departure_photos || [], marking.departure_videos || [], canDep, buktiBlockReason(marking, 'departure')) +
            buildBuktiPhaseCard(uidArr, 'arrival', t('arrival_section'), 'sj-bukti-arrival',
                marking.arrival_photos || [], marking.arrival_videos || [], canArr, buktiBlockReason(marking, 'arrival')) +
            '</div>';

        var sjBuktiGuardInterval = null;

        Swal.fire({
            title: t('bukti_modal_title') + ' — ' + (marking.code || marking.key),
            html: html,
            width: 780,
            showCloseButton: true,
            showConfirmButton: false,
            focusConfirm: false,
            heightAuto: false,
            customClass: { popup: 'sj-bukti-popup', container: 'sj-bukti-open' },
            didOpen: function () {
                var swalContainer = Swal.getContainer();
                if (swalContainer) {
                    swalContainer.classList.add('sj-bukti-open');
                    swalContainer.style.zIndex = '2000';
                    swalContainer.style.pointerEvents = 'auto';
                }
                var popup = Swal.getPopup();
                if (popup) {
                    popup.style.pointerEvents = 'auto';
                }
                sjUnlockModalFocus();
                var $popup = $(Swal.getHtmlContainer());
                sjEnableEditorFields($popup);
                sjBindEditorFieldGuard($popup);
                sjBuktiGuardInterval = window.setInterval(function () {
                    sjUnlockModalFocus();
                    sjEnableEditorFields($popup);
                }, 200);

                $popup.find('#' + uidDep + '_photos').on('change', function () {
                    renderLocalPhotoPreview($popup.find('#' + uidDep + '_photo_preview'), this.files);
                });
                $popup.find('#' + uidDep + '_videos').on('change', function () {
                    renderLocalVideoPreview($popup.find('#' + uidDep + '_video_preview'), this.files);
                });
                $popup.find('#' + uidArr + '_photos').on('change', function () {
                    renderLocalPhotoPreview($popup.find('#' + uidArr + '_photo_preview'), this.files);
                });
                $popup.find('#' + uidArr + '_videos').on('change', function () {
                    renderLocalVideoPreview($popup.find('#' + uidArr + '_video_preview'), this.files);
                });

                $popup.on('click', '.sj-bukti-save-btn', function () {
                    var phase = String($(this).data('phase') || '');
                    var phaseUid = String($(this).data('uid') || '');
                    var $btn = $(this);
                    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + esc(t('bukti_uploading')));
                    uploadMarkingMedia($root, state, marking.key, phase, '#' + phaseUid + '_photos', '#' + phaseUid + '_videos')
                        .finally(function () {
                            $btn.prop('disabled', false).html(
                                '<i class="bi bi-cloud-upload me-1"></i>' +
                                esc(phase === 'departure' ? t('bukti_save_departure') : t('bukti_save_arrival'))
                            );
                        });
                });
            },
            willClose: function () {
                var swalContainer = Swal.getContainer();
                if (swalContainer) {
                    swalContainer.classList.remove('sj-bukti-open');
                }
                if (sjBuktiGuardInterval) {
                    window.clearInterval(sjBuktiGuardInterval);
                    sjBuktiGuardInterval = null;
                }
                sjEditorContainer().off('.sjEdit');
                sjRestoreModalFocus();
            },
        });
    }

    function uploadMarkingMedia($root, state, markingKey, phase, photoSel, videoSel) {
        var photos = $(photoSel)[0];
        var videos = $(videoSel)[0];
        var hasPhoto = photos && photos.files && photos.files.length > 0;
        var hasVideo = videos && videos.files && videos.files.length > 0;

        if (!hasPhoto || !hasVideo) {
            showToast(t('bukti_require_both'), 'error');
            return Promise.resolve();
        }

        return compressPhotoFiles(photos.files).then(function (photoFiles) {
            var fd = new FormData();
            fd.append('marking_key', markingKey);
            fd.append('media_phase', phase);
            photoFiles.forEach(function (file) {
                fd.append('photos[]', file);
            });
            Array.prototype.forEach.call(videos.files, function (file) {
                fd.append('videos[]', file);
            });

            return $.ajax({
                url: state.upsertUrl.replace('__CONTAINER_ID__', String(state.currentContainerId)),
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': state.csrf },
            }).then(function (res) {
                showToast(res.message || 'Bukti tersimpan.');
                Swal.close();
                if (res && res.data) {
                    window.SuratJalanUi.render($root, res.data, state);
                }
            }).catch(function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message;
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    msg = Object.values(xhr.responseJSON.errors).flat().join(' ');
                }
                showToast(msg || 'Gagal upload bukti.', 'error');
            });
        });
    }

    if (window.SURAT_JALAN_PAGE_BOOT) {
        document.addEventListener('DOMContentLoaded', function () {
            var boot = window.SURAT_JALAN_PAGE_BOOT;
            var $root = $('#suratJalanPageRoot');
            var state = {
                csrf: boot.csrf,
                summaryUrl: boot.summaryUrl,
                upsertUrl: boot.upsertUrl,
                canBlankTemplate: !!boot.canBlankTemplate,
                blankTemplateUrl: boot.canBlankTemplate
                    ? (boot.blankTemplateUrl || boot.payload?.blank_template_url || '')
                    : '',
                blankTemplateLabel: boot.blankTemplateLabel || 'Template kosong',
                currentContainerId: boot.containerId,
                currentTripId: boot.payload?.trip?.id || null,
                isStandalonePage: !!boot.isStandalonePage,
                markingsByKey: {},
            };
            window.SuratJalanUi.bind($root, state);
            window.SuratJalanUi.render($root, boot.payload, state);
        });
    }
})();
</script>
