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

    var cfg = window._detailConfig || {};
    var csrf = cfg.csrf || '';
    var containerId = cfg.containerId;
    var remaining = cfg.remainingVolume;

    function toast(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'success');
        }
    }

    function formatCbm(value) {
        if (typeof window.formatCbm === 'function') {
            return window.formatCbm(value);
        }
        if (typeof window.truncateCbm === 'function') {
            return window.truncateCbm(value).toFixed(3);
        }
        return Number(value || 0).toFixed(3);
    }

    function applyDetailStats(data) {
        if (!data) {
            return;
        }
        var itemCount = typeof data.item_count === 'number'
            ? data.item_count
            : (typeof data.total_items === 'number' ? data.total_items : null);
        if (typeof data.remaining_volume === 'number') {
            remaining = data.remaining_volume;
            cfg.remainingVolume = remaining;
            $('#packModalRemainingCbm').text(formatCbm(data.remaining_volume) + ' CBM');
        }
        if (itemCount !== null) {
            $('#detailStatItems').text(itemCount);
            var badgeText = t('items_count').replace(':count', itemCount);
            $('#detailItemsBadge').html('<i class="bi bi-hash me-1"></i>' + badgeText);
        }
        if (typeof data.total_ctns === 'number') {
            $('#detailStatColy').text(data.total_ctns);
            $('#packingFootColy').text(data.total_ctns);
        }
        if (typeof data.used_volume === 'number') {
            $('#detailStatVolume').text(formatCbm(data.used_volume));
            $('#packingFootVolume').text(formatCbm(data.used_volume));
            $('#packModalUsedCbm').text(formatCbm(data.used_volume) + ' CBM');
        }
        if (typeof data.capacity_percent === 'number') {
            var pct = data.capacity_percent;
            var level = data.capacity_level || 'safe';
            var $cap = $('#detailStatCapacity');
            $cap.text(pct + '%');
            $cap.removeClass('text-danger text-warning text-accent');
            if (level === 'danger') {
                $cap.addClass('text-danger');
            } else if (level === 'warning') {
                $cap.addClass('text-warning');
            } else {
                $cap.addClass('text-accent');
            }
            var $fill = $('#detailCapacityBarFill');
            $fill.removeClass('danger warning safe').addClass(level);
            $fill.css('width', Math.min(pct, 100) + '%');
        }
    }

    function showPackingTableEmpty() {
        var $tbody = $('#packingTable tbody');
        if ($tbody.find('tr.packing-data-row').length > 0) {
            return;
        }
        $('#packingTableFoot').remove();
        $tbody.empty();
        if (cfg.packingTableUrl) {
            refreshPackingTable();
            return;
        }
        $tbody.append(
            '<tr class="packing-empty-row">' +
            '<td colspan="10" class="text-center py-4 packing-empty-cell">' +
            '<i class="bi bi-inbox" style="font-size:2rem;opacity:.4;color:var(--text-dim)"></i>' +
            '<div class="mt-2" style="color:var(--text-muted)">' + t('empty_container_items') + '</div>' +
            '</td></tr>'
        );
    }

    function refreshPackingTable() {
        var url = cfg.packingTableUrl;
        if (!url) {
            return $.Deferred().resolve().promise();
        }
        return $.get(url).done(function (res) {
            $('#packingTable tbody').html(res.tbody_html || '');
            if (res.tfoot_html) {
                if ($('#packingTableFoot').length) {
                    $('#packingTableFoot').replaceWith(res.tfoot_html);
                } else {
                    $('#packingTable').append(res.tfoot_html);
                }
            } else {
                $('#packingTableFoot').remove();
            }
        }).fail(function (xhr) {
            toast(xhr.responseJSON?.message || t('generic_failed'), 'error');
        });
    }

    // ── Add Packaging ──
    var packLoading = false;
    var $addPackModal = $('#addPackModal');

    function focusPackInput(delay) {
        setTimeout(function () {
            if (!$addPackModal.hasClass('show')) {
                return;
            }
            var el = document.getElementById('packNoIndex');
            if (el) {
                el.focus({ preventScroll: true });
            }
        }, delay == null ? 150 : delay);
    }

    function resetPackInput(delay) {
        $('#packNoIndex').val('');
        $('#packPreview').addClass('d-none').empty();
        focusPackInput(delay);
    }

    $('.btn-add-packaging').on('click', function () {
        bootstrap.Modal.getOrCreateInstance($addPackModal[0]).show();
    });

    $addPackModal.on('shown.bs.modal', function () {
        resetPackInput();
    });

    var packTimer;
    $('#packNoIndex').on('input', function () {
        clearTimeout(packTimer);
        var val = $(this).val().trim();
        if (val.length < 2) { $('#packPreview').addClass('d-none'); return; }
        packTimer = setTimeout(function () {
            $.get('/barang-lookup', { no_index: val }, function (res) {
                if (res.found) {
                    var fits = parseFloat(res.data.total_cbm) <= remaining;
                    $('#packPreview').html(
                        '<div class="d-flex justify-content-between">' +
                        '<div>' +
                        '<div class="fw-600" style="color:var(--text-primary)">' + res.data.no_index + '</div>' +
                        '<div class="small" style="color:var(--text-muted)">' + t('pack_preview_meta', {
                            marking: res.data.marking || '-',
                            cbm: res.data.total_cbm,
                            ctns: res.data.ctns
                        }) + '</div>' +
                        '</div>' +
                        '<div>' + (fits
                            ? '<span class="badge-glass badge-status-shipped">' + t('fit_load') + '</span>'
                            : '<span class="badge-glass badge-status-rejected">' + t('fit_no_load') + '</span>'
                        ) + '</div></div>'
                    ).removeClass('d-none');
                } else {
                    $('#packPreview').addClass('d-none');
                }
            });
        }, 250);
    });

    $('#packNoIndex').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#addPackForm').trigger('submit');
        }
    });

    $('#addPackForm').on('submit', function (e) {
        e.preventDefault();
        if (packLoading) {
            return;
        }
        var noIndex = $('#packNoIndex').val().trim();
        if (!noIndex) {
            focusPackInput();
            return;
        }

        packLoading = true;
        var $submit = $(this).find('[type="submit"]');
        $submit.prop('disabled', true);

        $.ajax({
            url: '/container/' + containerId + '/load',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: { no_index: noIndex },
            success: function (res) {
                toast(res.message || t('item_loaded', { no_index: noIndex }), 'success');
                applyDetailStats(res);
                refreshPackingTable().always(function () {
                    resetPackInput(200);
                });
            },
            error: function (xhr) {
                toast(xhr.responseJSON?.message || t('generic_failed'), 'error');
                resetPackInput();
            },
            complete: function () {
                packLoading = false;
                $submit.prop('disabled', false);
            },
        });
    });

    // ── Re-pricing / Ubah Harga ──
    var rpCurrentCbm = 0;
    var rpCurrentGw = 0;
    var rpCurrentThreshold = 0;

    function fmtRp(n) {
        return 'Rp ' + Number(n).toLocaleString('id');
    }

    function toggleRepriceWeightMode(isWeight) {
        $('#repricePricingMode').val(isWeight ? 'weight' : 'cbm');
        $('#repriceCbmFields').toggleClass('d-none', isWeight);
        $('#repriceWeightFields').toggleClass('d-none', !isWeight);
    }

    function calcRpPreview() {
        var extra = parseFloat($('#repriceExtra').val()) || 0;
        var priceCbm = parseFloat($('#repricePrice').val()) || 0;
        var weightPrice = parseFloat($('#repriceWeightPrice').val()) || 0;
        var cbmTotal = rpCurrentCbm * priceCbm;
        var weightTotal = rpCurrentGw * weightPrice;
        var isWeight = $('#repriceWeightMode').is(':checked');
        var activeBase = isWeight ? weightTotal : cbmTotal;

        $('#rpCalcCbm2').text(fmtRp(cbmTotal.toFixed(0)));
        $('#rpCalcWeight2').text(fmtRp(weightTotal.toFixed(0)));
        $('#rpCalcExtra2').text(fmtRp(extra.toFixed(0)));
        $('#rpCalcTotal2').text(fmtRp((activeBase + extra).toFixed(0)));
    }

    $(document).on('click', '.btn-reprice', function () {
        rpCurrentCbm = parseFloat($(this).data('cbm')) || 0;
        rpCurrentGw = parseFloat($(this).data('gw')) || 0;
        rpCurrentThreshold = parseFloat($(this).data('threshold')) || 0;
        $('#repriceBarangId').val($(this).data('id'));
        $('#repriceIndex').text($(this).data('index'));
        $('#repriceMarking').text($(this).data('marking') || '-');
        $('#repriceCbm').text(formatCbm(rpCurrentCbm));
        $('#repriceGw').text(rpCurrentGw.toFixed(2));
        $('#repriceThreshold').text(rpCurrentThreshold > 0 ? rpCurrentThreshold.toFixed(2) : '-');
        $('#repriceWeightMode').prop('checked', false);
        toggleRepriceWeightMode(false);
        $('#repricePrice').val($(this).data('price'));
        $('#repriceWeightPrice').val($(this).data('weight-price') || 0);
        $('#repriceExtra').val($(this).data('extra'));
        $('#repriceReason').val('');
        $('#repriceProof').val('');
        calcRpPreview();
        new bootstrap.Modal('#repriceModal').show();
    });

    $('#repriceWeightMode').on('change', function () {
        toggleRepriceWeightMode($(this).is(':checked'));
        calcRpPreview();
    });

    $('#repricePrice, #repriceExtra, #repriceWeightPrice').on('input', calcRpPreview);

    $(document).on('click', '.btn-unload-barang', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }

        var barangId = $btn.data('id');
        var noIndex = $btn.data('index') || '';
        var marking = $btn.data('marking') || '-';
        var unloadTemplate = cfg.unloadUrlTemplate || '';
        var unloadUrl = unloadTemplate.replace('__BARANG_ID__', String(barangId));

        if (!unloadUrl || unloadUrl.indexOf('__BARANG_ID__') !== -1) {
            Swal.fire({
                icon: 'error',
                title: t('failed'),
                text: t('generic_failed'),
                confirmButtonColor: '#dc2626',
            });
            return;
        }

        Swal.fire({
            title: t('unload_shipment_title'),
            text: t('unload_shipment_text')
                .replace(':index', noIndex)
                .replace(':marking', marking),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            confirmButtonText: t('confirm_delete'),
            cancelButtonText: t('cancel'),
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $btn.prop('disabled', true);

            Swal.fire({
                title: t('deleting'),
                text: t('please_wait'),
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    Swal.showLoading();
                },
            });

            $.ajax({
                url: unloadUrl,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                data: {
                    _token: csrf,
                    _method: 'DELETE',
                },
                success: function (res) {
                    var $row = $btn.closest('tr');
                    $row.fadeOut(250, function () {
                        $(this).remove();
                        showPackingTableEmpty();
                    });
                    applyDetailStats(res);

                    Swal.fire({
                        icon: 'success',
                        title: t('success_delete'),
                        text: res.message || t('deleted_ok'),
                        confirmButtonColor: '#16a34a',
                        confirmButtonText: t('understand'),
                        timer: 2200,
                        timerProgressBar: true,
                    });
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    var msg = xhr.responseJSON?.message
                        || xhr.responseJSON?.error
                        || xhr.statusText
                        || t('delete_failed');
                    Swal.fire({
                        icon: 'error',
                        title: t('failed'),
                        text: msg,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: t('understand'),
                    });
                },
            });
        });
    });

    $('#repriceForm').on('submit', function (e) {
        e.preventDefault();
        var reason = ($('#repriceReason').val() || '').trim();
        if (!reason) {
            showToast(t('repricing_reason_required'), 'error');
            return;
        }
        var proofInput = document.getElementById('repriceProof');
        if (!proofInput || !proofInput.files || !proofInput.files.length) {
            showToast(t('repricing_proof_required'), 'error');
            return;
        }
        var isWeight = $('#repriceWeightMode').is(':checked');
        if (isWeight && (parseFloat($('#repriceWeightPrice').val()) || 0) <= 0) {
            showToast(t('repricing_weight_price_required'), 'error');
            return;
        }

        var idx = $('#repriceIndex').text();
        Swal.fire({
            title: t('change_price_title'),
            text: t('change_price_text', { index: idx }),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: t('confirm_save'),
            cancelButtonText: t('cancel')
        }).then(function (result) {
            if (result.isConfirmed) {
                var id = $('#repriceBarangId').val();
                var formData = new FormData();
                formData.append('pricing_mode', isWeight ? 'weight' : 'cbm');
                formData.append('extra_charge', parseFloat($('#repriceExtra').val()) || 0);
                formData.append('reason', reason);
                formData.append('proof_image', proofInput.files[0]);
                if (isWeight) {
                    formData.append('weight_price', parseFloat($('#repriceWeightPrice').val()) || 0);
                } else {
                    formData.append('price_per_cbm', parseFloat($('#repricePrice').val()) || 0);
                }

                $.ajax({
                    url: '/finance/reprice/' + id,
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf },
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        showToast(res.message);
                        bootstrap.Modal.getInstance('#repriceModal').hide();
                        if (typeof window.refreshPengajuanNavBadge === 'function') {
                            window.refreshPengajuanNavBadge();
                        }
                        setTimeout(function () { location.reload(); }, 800);
                    },
                    error: function (xhr) { showToast(xhr.responseJSON?.message || t('failed_update_price'), 'error'); }
                });
            }
        });
    });
});
