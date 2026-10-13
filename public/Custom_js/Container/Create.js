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

    function formatCapacityPercent(percent) {
        var tpl = $('#estimatePercent').data('capacity-template') || (percent + '%');
        return String(tpl).replace(':percent', percent);
    }

    if (window.initSelect2) {
        window.initSelect2('#containerCreateForm .select2');
        window.initSelect2('#markingSelect', { allowClear: true, width: '100%' });
    }

    var cfg = window._containerCreateConfig;
    var staged = [];
    var previewBarangs = [];
    var maxVolume = 0;

    $('#containerType').on('change', function () {
        updateSpec();
        updateEstimation();
        refreshMarkingPreview();
    });
    updateSpec();

    if (window.MarkingEstimationPreview) {
        window.MarkingEstimationPreview.initDateFilter(function () {
            refreshMarkingPreview();
        });
    }

    $('#markingSelect').on('change', function () {
        refreshMarkingPreview();
    });

    function getMaxVolume() {
        var opt = $('#containerType option:selected');
        return parseFloat(opt.data('v')) || 0;
    }

    function setPreviewFitBadge(fits) {
        $('#previewFitBadge')
            .removeClass('badge-status-shipped badge-status-rejected')
            .addClass(fits ? 'badge-status-shipped' : 'badge-status-rejected')
            .text(fits ? t('fit_load') : t('fit_no_load'));
    }

    function resetPreviewFitBadge() {
        $('#previewFitBadge')
            .removeClass('badge-status-shipped badge-status-rejected')
            .text('-');
    }

    function updateSpec() {
        var opt = $('#containerType option:selected');
        maxVolume = getMaxVolume();
        $('#specL').text(opt.data('l') || '-');
        $('#specW').text(opt.data('w') || '-');
        $('#specH').text(opt.data('h') || '-');
        $('#specV').text(maxVolume ? maxVolume + ' CBM' : '-');
    }

    function refreshMarkingPreview() {
        var markingId = $('#markingSelect').val() || '';
        resetPreview();

        if (window.MarkingEstimationPreview && !window.MarkingEstimationPreview.canLoadPreview(markingId)) {
            return;
        }

        if (!getMaxVolume()) {
            showToast(t('select_container_type_first'), 'warning');
            return;
        }

        var params = {
            max_volume: getMaxVolume(),
            staged_cbm: getStageTotals().cbm,
        };
        if (markingId) {
            params.marking_id = markingId;
        }
        if (window.MarkingEstimationPreview) {
            Object.assign(params, window.MarkingEstimationPreview.getDateFilterParams());
        }
        $.get(cfg.markingPreviewUrl, params, function (res) {
            if (!res.barangs || !res.barangs.length) {
                $('#markingPreviewEmpty').removeClass('d-none').html(
                    '<i class="bi bi-exclamation-circle" style="font-size:2rem;opacity:.35;color:var(--text-dim)"></i>' +
                    '<div class="mt-2 small" style="color:var(--text-muted)">' + esc(t('no_inbound_for_marking')) + '</div>'
                );
                return;
            }

            previewBarangs = res.barangs;
            $('#markingPreviewEmpty').addClass('d-none');
            $('#markingPreviewPanel').removeClass('d-none');

            if (window.MarkingEstimationPreview) {
                window.MarkingEstimationPreview.applyPreviewHeader(res);
            }
            setPreviewFitBadge(res.fits_remaining);

            renderPreviewBarangs();
            updatePreviewAddButton();
        }).fail(function (xhr) {
            var msg = xhr.responseJSON?.message || t('failed_load_marking_preview');
            if (typeof msg === 'string' && msg.length > 180) {
                msg = t('failed_load_marking_preview');
            }
            showToast(msg, 'error', { duration: 5000 });
        });
    }

    function resetPreview() {
        previewBarangs = [];
        $('#markingPreviewPanel').addClass('d-none');
        $('#markingPreviewEmpty').removeClass('d-none').html(
            '<i class="bi bi-bookmark" style="font-size:2rem;opacity:.35;color:var(--text-dim)"></i>' +
            '<div class="mt-2 small" style="color:var(--text-muted)">' + esc(t('preview_empty_create')) + '</div>'
        );
        $('#previewBarangList').empty();
        $('#btnAddToStage').prop('disabled', true);
        resetPreviewFitBadge();
    }

    function renderPreviewBarangs() {
        if (!window.MarkingEstimationPreview) {
            $('#previewBarangList').empty();
            return;
        }
        window.MarkingEstimationPreview.renderPreviewBarangList('#previewBarangList', previewBarangs, {
            isStaged: isStaged,
        });
    }

    function updatePreviewAddButton() {
        var selected = getSelectedPreviewBarangs();
        $('#btnAddToStage').prop('disabled', !selected.length);
    }

    function getSelectedPreviewBarangs() {
        if (window.MarkingEstimationPreview && typeof window.MarkingEstimationPreview.getSelectedItems === 'function') {
            return window.MarkingEstimationPreview.getSelectedItems();
        }
        return previewBarangs.filter(function (item) {
            return !isStaged(item.id);
        });
    }

    if (window.MarkingEstimationPreview) {
        window.MarkingEstimationPreview.bindPreviewCheckAllHandlers(function () {
            updatePreviewTotals();
            updatePreviewAddButton();
        });
    }

    function updatePreviewTotals() {
        var selected = getSelectedPreviewBarangs();
        var ctns = 0;
        var cbm = 0;
        var gw = 0;

        selected.forEach(function (item) {
            ctns += parseInt(item.ctns, 10) || 0;
            cbm += parseFloat(item.total_cbm) || 0;
            gw += parseFloat(item.total_gw) || 0;
        });

        if (typeof window.truncateCbm === 'function') {
            cbm = window.truncateCbm(cbm);
        }

        $('#previewCtns').text(ctns);
        $('#previewCbm').text(window.fmtCbm ? window.fmtCbm(cbm) : Number(cbm).toFixed(3));
        $('#previewGw').text(gw.toFixed(2));

        setPreviewFitBadge(cbm <= getRemainingVolume());
    }

    $('#btnAddToStage').on('click', function () {
        var selected = getSelectedPreviewBarangs();
        if (!selected.length) {
            return;
        }

        selected.forEach(function (item) {
            staged.push({
                id: item.id,
                marking: item.marking,
                no_index: item.no_index,
                ctns: item.ctns,
                total_cbm: item.total_cbm,
                total_gw: item.total_gw,
            });
        });

        renderStageTable();
        renderPreviewBarangs();
        updatePreviewAddButton();
        updateEstimation();
        refreshMarkingPreview();
        showToast(t('items_added_staging', { count: selected.length }));
    });

    function isStaged(id) {
        return staged.some(function (item) {
            return item.id === id;
        });
    }

    function getStageTotals() {
        return staged.reduce(function (acc, item) {
            acc.ctns += parseInt(item.ctns, 10) || 0;
            acc.cbm += parseFloat(item.total_cbm) || 0;
            acc.gw += parseFloat(item.total_gw) || 0;
            return acc;
        }, { ctns: 0, cbm: 0, gw: 0 });
    }

    function getRemainingVolume() {
        return Math.max(0, getMaxVolume() - getStageTotals().cbm);
    }

    function renderStageTable() {
        var $body = $('#stageTableBody');
        $body.empty();

        if (!staged.length) {
            $body.append(
                '<tr id="stageEmptyRow">' +
                '<td colspan="6" class="text-center py-4">' +
                '<i class="bi bi-inbox" style="font-size:2rem;opacity:.4;color:var(--text-dim)"></i>' +
                '<div class="mt-2" style="color:var(--text-muted)">' + esc(t('staging_empty')) + '</div>' +
                '</td></tr>'
            );
            $('#stageTableFoot').addClass('d-none');
            $('#stageCount').text('0');
            $('#btnClearStage').prop('disabled', true);
            updateEstimation();
            return;
        }

        staged.forEach(function (item, index) {
            $body.append(
                '<tr>' +
                '<td><span class="badge-glass marking-badge">' + esc(item.marking || '-') + '</span></td>' +
                '<td class="fw-600" style="color:var(--text-primary)">' + esc(item.no_index) + '</td>' +
                '<td>' + item.ctns + '</td>' +
                '<td class="fw-700 text-accent">' + (window.fmtCbm ? window.fmtCbm(item.total_cbm) : Number(item.total_cbm).toFixed(3)) + '</td>' +
                '<td>' + Number(item.total_gw).toFixed(2) + '</td>' +
                '<td class="text-center"><button type="button" class="btn btn-glass btn-sm btn-remove-stage" data-index="' + index + '"><i class="bi bi-trash"></i></button></td>' +
                '</tr>'
            );
        });

        var totals = getStageTotals();
        $('#stageTotalCtns').text(totals.ctns);
        $('#stageTotalCbm').text(window.fmtCbm ? window.fmtCbm(totals.cbm) : Number(totals.cbm).toFixed(3));
        $('#stageTotalGw').text(totals.gw.toFixed(2));
        $('#stageCount').text(staged.length);
        $('#stageTableFoot').removeClass('d-none');
        $('#btnClearStage').prop('disabled', false);
        updateEstimation();
    }

    $(document).on('click', '.btn-remove-stage', function () {
        var index = parseInt($(this).data('index'), 10);
        staged.splice(index, 1);
        renderStageTable();
        renderPreviewBarangs();
        updatePreviewAddButton();
        updatePreviewTotals();
        refreshMarkingPreview();
    });

    $('#btnClearStage').on('click', function () {
        staged = [];
        renderStageTable();
        renderPreviewBarangs();
        updatePreviewAddButton();
        updatePreviewTotals();
        refreshMarkingPreview();
    });

    function updateEstimation() {
        var totals = getStageTotals();
        var projectedUsed = totals.cbm;
        var remaining = getMaxVolume() - projectedUsed;
        var percent = getMaxVolume() > 0 ? (projectedUsed / getMaxVolume()) * 100 : 0;
        var fits = projectedUsed <= getMaxVolume();
        var level = percent >= 95 ? 'danger' : (percent >= 75 ? 'warning' : 'safe');

        $('#estimateUsedCbm').text(window.fmtCbm ? window.fmtCbm(projectedUsed) : Number(projectedUsed).toFixed(3));
        $('#estimateRemainingCbm').text(window.fmtCbm ? window.fmtCbm(Math.max(0, remaining)) : Number(Math.max(0, remaining)).toFixed(3));
        $('#estimateStatus')
            .text(fits ? t('fit_load') : t('fit_no_load'))
            .toggleClass('text-danger', !fits)
            .toggleClass('text-accent', fits);
        $('#estimatePercent').text(formatCapacityPercent(percent.toFixed(1)));
        $('#estimateCapacityBar')
            .removeClass('safe warning danger')
            .addClass(level)
            .css('width', Math.min(percent, 100) + '%');
    }

    $('#btnSaveContainer').on('click', function () {
        var form = document.getElementById('containerCreateForm');
        if (!form.reportValidity()) {
            return;
        }

        var totals = getStageTotals();
        if (totals.cbm > getMaxVolume()) {
            showToast(t('staging_exceeds_capacity'), 'error');
            return;
        }

        var payload = $('#containerCreateForm').serializeArray();
        payload.push({ name: '_token', value: cfg.csrf });
        staged.forEach(function (item) {
            payload.push({ name: 'barang_ids[]', value: item.id });
        });

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: cfg.storeUrl,
            method: 'POST',
            data: $.param(payload),
            success: function (res) {
                showToast(res.message || t('container_created'));
                setTimeout(function () {
                    window.location.href = res.redirect || cfg.indexUrl;
                }, 800);
            },
            error: function (xhr) {
                var json = xhr.responseJSON;
                var msg = json && json.errors
                    ? Object.values(json.errors).flat().join(', ')
                    : (json && json.message ? json.message : t('generic_failed'));
                showToast(msg, 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            },
        });
    });

    function esc(value) {
        return $('<span>').text(value || '').html();
    }

    updateEstimation();
});
