document.addEventListener('DOMContentLoaded', function () {
    var $form = $('#deliveryTripForm');
    if (!$form.length) {
        return;
    }

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    var selected = {};
    var lastRows = [];
    var searchTimer = null;
    var listLoading = false;
    var listRequest = null;
    var cfg = window.deliveryTripConfig || {};

    $('#barangSearch').on('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(loadBarangs, 200);
    });

    $('#btnClearSel').on('click', function () {
        selected = {};
        syncCheckboxes();
        refreshUi();
    });

    $('#truckPlate, #driverName').on('input', refreshUi);

    $form.on('submit', function (e) {
        e.preventDefault();
        if (!canSubmit()) {
            if (!Object.keys(selected).length) {
                showToast(t('no_items', 'Pilih minimal satu pengiriman.'), 'error');
            } else {
                showToast(t('fill_required', 'Lengkapi no polisi dan nama supir.'), 'error');
            }
            return;
        }

        var fd = new FormData($form[0]);
        fd.delete('barang_ids[]');
        Object.keys(selected).forEach(function (id) {
            fd.append('barang_ids[]', String(id));
        });

        setSubmitLoading(true);
        $.ajax({
            url: window.deliveryTripStoreUrl,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (res) {
                showToast(res.message || 'OK');
                if (res.redirect) {
                    window.location.href = res.redirect;
                }
            },
            error: function (xhr) {
                var json = xhr.responseJSON;
                showToast(
                    (json && json.message)
                    || (json && json.errors && Object.values(json.errors).flat().join('\n'))
                    || t('save_failed', 'Gagal menyimpan.'),
                    'error',
                );
            },
            complete: function () {
                setSubmitLoading(false);
            },
        });
    });

    $(document).on('change', '.barang-pick', function () {
        var id = Number($(this).data('id'));
        var row = lastRows.find(function (item) {
            return item.id === id;
        });

        if (this.checked && row) {
            selected[id] = row;
        } else {
            delete selected[id];
        }

        refreshUi();
    });

    $(document).on('click', '.dt-barang-card', function (e) {
        if ($(e.target).is('input, label')) {
            return;
        }

        var $checkbox = $(this).find('.barang-pick');
        $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
    });

    function loadBarangs() {
        if (listRequest && typeof listRequest.abort === 'function') {
            listRequest.abort();
        }

        listLoading = true;
        setListLoading(true);

        listRequest = $.get(window.deliveryTripEligibleUrl, {
            search: $('#barangSearch').val() || '',
        })
            .done(function (rows) {
                lastRows = Array.isArray(rows) ? rows : [];
                renderList(lastRows);
            })
            .fail(function (xhr) {
                if (xhr && xhr.statusText === 'abort') {
                    return;
                }

                lastRows = [];
                renderList([]);
                showToast(
                    (xhr.responseJSON && xhr.responseJSON.message)
                    || t('load_failed', 'Gagal memuat daftar pengiriman.'),
                    'error',
                );
            })
            .always(function () {
                listLoading = false;
                listRequest = null;
                setListLoading(false);
            });
    }

    function renderList(rows) {
        var $list = $('#barangList');
        var $empty = $('#barangListEmpty');

        $('#listResultBadge').text(
            rows.length
                ? rows.length + ' ' + t('items_available', 'pengiriman tersedia')
                : '0',
        );

        if (!rows.length) {
            $list.addClass('d-none').empty();
            $empty.removeClass('d-none');
            refreshUi();
            return;
        }

        $empty.addClass('d-none');
        $list.removeClass('d-none');

        var html = rows.map(function (row) {
            var isSelected = !!selected[row.id];
            var statusClass = 'badge-status-' + (row.status || 'eta');

            return '<div class="col-sm-6">' +
                '<div class="dt-barang-card glass-solid d-flex gap-2' + (isSelected ? ' is-selected' : '') + '" data-id="' + row.id + '">' +
                '<input type="checkbox" class="form-check-input barang-pick flex-shrink-0" data-id="' + row.id + '"' +
                (isSelected ? ' checked' : '') + ' id="pick-' + row.id + '">' +
                '<div class="flex-grow-1 min-w-0">' +
                '<div class="d-flex justify-content-between align-items-start gap-1">' +
                '<span class="dt-card-index">' + esc(row.no_index) + '</span>' +
                '<span class="badge-glass ' + esc(statusClass) + '" style="font-size:.6rem">' + esc(row.status_label || row.status) + '</span>' +
                '</div>' +
                '<div class="small text-muted-glass text-truncate">' + esc(row.marking || '—') + '</div>' +
                '<div class="small text-muted-glass text-truncate">' + esc(row.customer_name || '—') + '</div>' +
                containerLine(row) +
                etaLine(row) +
                '<div class="small mt-1"><span class="text-muted-glass">CTNS</span> ' + Number(row.ctns || 0) +
                ' · <span class="text-muted-glass">CBM</span> ' + (window.fmtCbm ? window.fmtCbm(row.total_cbm || 0) : Number(row.total_cbm || 0).toFixed(3)) + '</div>' +
                '</div></div></div>';
        }).join('');

        $list.html(html);
        refreshUi();
    }

    function syncCheckboxes() {
        $('.barang-pick').each(function () {
            var id = Number($(this).data('id'));
            var isSelected = !!selected[id];
            $(this).prop('checked', isSelected);
            $(this).closest('.dt-barang-card').toggleClass('is-selected', isSelected);
        });
    }

    function refreshUi() {
        syncCheckboxes();

        var rows = Object.values(selected).sort(function (a, b) {
            return String(a.no_index || '').localeCompare(String(b.no_index || ''), undefined, {
                numeric: true,
                sensitivity: 'base',
            });
        });

        var count = rows.length;
        var ctns = 0;
        var cbm = 0;

        rows.forEach(function (row) {
            ctns += Number(row.ctns) || 0;
            cbm += Number(row.total_cbm) || 0;
        });

        $('#summaryItems').text(count);
        $('#summaryCtns').text(ctns);
        $('#summaryCbm').text(window.fmtCbm ? window.fmtCbm(cbm) : Number(cbm).toFixed(3));
        $('#btnClearSel').prop('disabled', count === 0);

        var $chips = $('#selectedChips');
        if (!count) {
            $chips.html('<li class="text-muted-glass small">' + esc(t('no_selection_yet', 'Belum ada pilihan')) + '</li>');
        } else {
            var preview = rows.slice(0, 6).map(function (row) {
                return '<li><strong>' + esc(row.no_index) + '</strong> · ' + esc(row.marking || '') + '</li>';
            }).join('');

            if (count > 6) {
                preview += '<li class="text-muted-glass small">+' + (count - 6) + ' ' + t('more_items', 'lainnya') + '</li>';
            }

            $chips.html(preview);
        }

        var truckOk = $.trim($('#truckPlate').val()) !== '';
        var itemsOk = count > 0;
        var ready = canSubmit();

        updateSteps(truckOk, itemsOk);
        $('#btnSubmitTrip').prop('disabled', !ready);
        $('#footerHint').text(
            ready
                ? t('footer_hint_ready', 'Siap dibuat. Supir akan konfirmasi foto & video di halaman berikutnya.')
                : t('footer_hint_incomplete', 'Lengkapi truck, supir, dan pilih minimal 1 pengiriman.'),
        );
        setActiveStep(ready ? 3 : (itemsOk ? 2 : (truckOk ? 2 : 1)));
    }

    function updateSteps(truckOk, itemsOk) {
        $('.dt-step').removeClass('dt-step-active');
        if (itemsOk) {
            $('.dt-step[data-step="2"]').addClass('dt-step-active');
        } else if (truckOk) {
            $('.dt-step[data-step="2"]').addClass('dt-step-active');
        } else {
            $('.dt-step[data-step="1"]').addClass('dt-step-active');
        }
    }

    function setActiveStep(step) {
        $('.dt-step').removeClass('dt-step-active');
        $('.dt-step[data-step="' + step + '"]').addClass('dt-step-active');
        if (step >= 2) {
            $('.dt-step[data-step="1"]').addClass('dt-step-active');
        }
        if (step >= 3) {
            $('.dt-step[data-step="2"]').addClass('dt-step-active');
        }
    }

    function canSubmit() {
        return $.trim($('#truckPlate').val()) !== ''
            && $.trim($('#driverName').val()) !== ''
            && Object.keys(selected).length > 0;
    }

    function setListLoading(on) {
        $('#barangListLoading').toggleClass('d-none', !on);
        if (on) {
            $('#barangList').addClass('d-none');
            $('#barangListEmpty').addClass('d-none');
        }
    }

    function setSubmitLoading(on) {
        $('#btnSubmitTrip').prop('disabled', on || !canSubmit());
        $('#btnSubmitSpinner').toggleClass('d-none', !on);
        $('#btnSubmitIcon').toggleClass('d-none', on);
    }

    function esc(s) {
        return $('<span>').text(s == null ? '' : s).html();
    }

    function containerLine(row) {
        var pieces = [];
        if (row.container_partai) {
            pieces.push(esc(row.container_partai));
        }
        if (row.container_no) {
            pieces.push(esc(row.container_no));
        }
        if (!pieces.length) {
            return '';
        }

        return '<div class="small text-muted-glass text-truncate">' + pieces.join(' · ') + '</div>';
    }

    function etaLine(row) {
        if (!row.container_eta_label) {
            return '';
        }

        var rel = row.container_eta_relative ? ' · ' + esc(row.container_eta_relative) : '';
        var vs = cfg.systemDateLabel
            ? ' <span class="text-muted-glass">(' + esc(t('vs_system_date', 'vs')) + ' ' + esc(cfg.systemDateLabel) + ')</span>'
            : '';

        return '<div class="small text-accent">ETA ' + esc(row.container_eta_label) + rel + vs + '</div>';
    }

    loadBarangs();
    refreshUi();
});
