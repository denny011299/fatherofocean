document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('pricingTable')) {
        return;
    }

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }
    var table;
    var currentCbm = 0;
    var currentGw = 0;
    var currentThreshold = 0;
    var currentMinCbm = 0;
    var filterTimer;
    var currentPending = null;
    var modalPrices = { cbm: 0, weight: 0, extra: 0, minCharge: 0, threshold: 0, costCbm: 0, costWeight: 0 };

    initTable();
    refreshData();

    window.financePricingOnTabShow = function () {
        if (table) {
            table.columns.adjust().draw(false);
        }
    };

    $('#filterSearch').on('input', function () {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(refreshData, 400);
    });
    $('#filterStartLoadDate, #filterEndLoadDate').on('change', refreshData);
    $('#filterShow').on('change', refreshData);
    $('#btnResetFilter').on('click', function () {
        $('#filterSearch').val('');
        $('#filterStartLoadDate').val('');
        $('#filterEndLoadDate').val('');
        $('#filterShow').val('').trigger('change.select2');
        refreshData();
    });

    $(document).on('click', '.btn-reprice', function () {
        var $btn = $(this);
        currentCbm = parseFloat($btn.data('cbm')) || 0;
        currentGw = parseFloat($btn.data('gw')) || 0;
        currentPending = $btn.data('pending') ? JSON.parse($btn.attr('data-pending')) : null;
        currentMinCbm = parseFloat($btn.data('min-cbm')) || 0;
        currentThreshold = parseFloat($btn.data('threshold')) || 0;
        if (currentPending && currentPending.new_minimum_charge_cbm != null) {
            currentMinCbm = parseFloat(currentPending.new_minimum_charge_cbm) || 0;
        }
        if (currentPending && currentPending.new_weight_threshold_kg != null) {
            currentThreshold = parseFloat(currentPending.new_weight_threshold_kg) || 0;
        }
        $('#rpBarangId').val($btn.data('id'));
        $('#rpIndex').text($btn.data('index'));
        $('#rpMarking').text($btn.data('marking') || '-');
        $('#rpCbm').text(window.fmtCbm ? window.fmtCbm(currentCbm) : Number(currentCbm).toFixed(3));
        $('#rpGw').text(currentGw.toFixed(2));
        updateStatusBanner();

        var pricing = readRepricePricing($btn);
        modalPrices.cbm = pricing.price_per_cbm;
        modalPrices.weight = pricing.weight_price;
        modalPrices.extra = pricing.extra_charge;
        modalPrices.minCharge = currentMinCbm;
        modalPrices.threshold = currentThreshold;
        modalPrices.costCbm = parseFloat($btn.data('cost-cbm')) || 0;
        modalPrices.costWeight = parseFloat($btn.data('cost-weight')) || 0;
        $('#rpReason').val('');
        $('#rpProof').val('');

        updatePendingInfo();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('repriceModal')).show();
    });

    $('#repriceModal').on('hidden.bs.modal', function () {
        setRepriceSubmitLoading(false);
        $('#repriceModal .mask-currency').each(function () {
            if (this.inputmask && window.Inputmask) {
                window.Inputmask.remove(this);
            }
        });
    });

    $('#repriceModal').on('shown.bs.modal', function () {
        if (typeof window.initCurrencyMask === 'function') {
            window.initCurrencyMask('#repriceModal .mask-currency');
        }
        setCurrencyInput('#rpPrice', modalPrices.cbm);
        setCurrencyInput('#rpWeightPrice', modalPrices.weight);
        setCurrencyInput('#rpExtra', modalPrices.extra);
        $('#rpMinCharge').val(modalPrices.minCharge > 0 ? String(modalPrices.minCharge) : '0');
        $('#rpMinChargeHint').text(
            t('repricing_min_charge_hint', 'Min. charge saat ini') + ': '
            + (window.fmtCbm ? window.fmtCbm(modalPrices.minCharge) : Number(modalPrices.minCharge).toFixed(3))
            + ' CBM',
        );
        $('#rpThreshold').val(modalPrices.threshold > 0 ? String(Math.round(modalPrices.threshold)) : '0');
        $('#rpThresholdHint').text(
            t('repricing_threshold_hint', 'Threshold saat ini') + ': '
            + (modalPrices.threshold > 0 ? Number(modalPrices.threshold).toFixed(2) : '-'),
        );
        if (typeof _canEditCostPrice !== 'undefined' && _canEditCostPrice) {
            setCurrencyInput('#rpCostPrice', modalPrices.costCbm);
            setCurrencyInput('#rpCostWeightPrice', modalPrices.costWeight);
        }
        updatePriceHints();
        calcPreview();
    });

    $('#rpSaveCostBtn').on('click', function () {
        if (typeof _canEditCostPrice === 'undefined' || !_canEditCostPrice) return;

        var id = $('#rpBarangId').val();
        var costCbm = currencyValue('#rpCostPrice');
        var costWeight = currencyValue('#rpCostWeightPrice');

        $.post('/finance/cost-price/' + id, {
            cost_price_per_cbm: costCbm,
            cost_weight_price: costWeight,
        }, function (res) {
            showToast(res.message || t('cost_price_saved', 'Cost price saved.'));
            modalPrices.costCbm = costCbm;
            modalPrices.costWeight = costWeight;
            refreshData();
        }).fail(function (xhr) {
            showToast(xhr.responseJSON?.message || t('cost_price_save_failed', 'Failed to save cost price.'), 'error');
        });
    });

    $('#rpPrice, #rpExtra, #rpWeightPrice, #rpMinCharge, #rpThreshold').on('input keyup change', function () {
        currentMinCbm = parseFloat(String($('#rpMinCharge').val() || '').replace(',', '.')) || 0;
        currentThreshold = parseFloat(String($('#rpThreshold').val() || '').replace(',', '.')) || 0;
        calcPreview();
    });

    $('#repriceForm').on('submit', function (e) {
        e.preventDefault();
        if (typeof _canSubmitRepricing === 'undefined' || !_canSubmitRepricing) return;

        var reason = ($('#rpReason').val() || '').trim();
        if (!reason) {
            showToast(t('repricing_reason_required', 'Notes / reason is required.'), 'error');
            return;
        }

        var proofInput = document.getElementById('rpProof');
        if (!proofInput || !proofInput.files || !proofInput.files.length) {
            showToast(t('repricing_proof_required', 'Proof image is required.'), 'error');
            return;
        }

        var priceCbmVal = currencyValue('#rpPrice');
        var weightPriceVal = currencyValue('#rpWeightPrice');
        if (priceCbmVal <= 0) {
            showToast(t('repricing_price_cbm_required', 'Harga per CBM wajib diisi.'), 'error');
            return;
        }
        if (weightPriceVal <= 0) {
            showToast(t('repricing_weight_price_required', 'Harga per kg wajib diisi.'), 'error');
            return;
        }

        var idx = $('#rpIndex').text();
        Swal.fire({
            title: t('repricing_confirm_submit_title', 'Submit repricing?'),
            text: (t('repricing_confirm_submit_text', 'Submit new pricing for serial no. :index for approval?') || '')
                .replace(':index', idx),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: t('repricing_submit', 'Submit for approval'),
            cancelButtonText: t('cancel', 'Cancel'),
            confirmButtonColor: 'var(--accent, #818cf8)',
            cancelButtonColor: '#6b7280',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            var id = $('#rpBarangId').val();
            var preview = shippingPreview(priceCbmVal, weightPriceVal);
            var formData = new FormData();
            formData.append('pricing_mode', preview.method);
            formData.append('price_per_cbm', priceCbmVal);
            formData.append('weight_price', weightPriceVal);
            formData.append('extra_charge', currencyValue('#rpExtra'));
            formData.append('minimum_charge_cbm', parseFloat(String($('#rpMinCharge').val() || '').replace(',', '.')) || 0);
            formData.append('weight_threshold_kg', parseFloat(String($('#rpThreshold').val() || '').replace(',', '.')) || 0);
            formData.append('reason', reason);
            formData.append('proof_image', proofInput.files[0]);

            setRepriceSubmitLoading(true);

            $.ajax({
                url: '/finance/reprice/' + id,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function (res) {
                    showToast(res.message || t('repricing_submitted', 'Repricing submitted.'));
                    bootstrap.Modal.getInstance(document.getElementById('repriceModal')).hide();
                    refreshData();
                    if (typeof window.refreshPengajuanNavBadge === 'function') {
                        window.refreshPengajuanNavBadge();
                    }
                },
                error: function (xhr) {
                    var json = xhr.responseJSON;
                    if (json && json.errors) {
                        showToast(Object.values(json.errors).flat().join('\n'), 'error');
                        return;
                    }
                    showToast((json && json.message) || t('failed_update_pricing', 'Failed to submit repricing'), 'error');
                },
                complete: function () {
                    setRepriceSubmitLoading(false);
                },
            });
        });
    });

    $(document).on('click', '.btn-repricing-approve', function () {
        var $btn = $(this);
        var id = $btn.data('request-id');
        var idx = $btn.data('index');
        Swal.fire({
            title: t('repricing_confirm_approve_title', 'Approve repricing?'),
            text: (t('repricing_confirm_approve_text', 'Apply proposed price for :index?') || '').replace(':index', idx),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: t('repricing_approve', 'Approve'),
            cancelButtonText: t('cancel', 'Cancel'),
            confirmButtonColor: 'var(--accent, #818cf8)',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.post('/finance/repricing/' + id + '/approve', function (res) {
                showToast(res.message || t('repricing_approved', 'Approved'));
                refreshData();
                if (typeof window.refreshPengajuanNavBadge === 'function') {
                    window.refreshPengajuanNavBadge();
                }
            }).fail(function (xhr) {
                showToast(xhr.responseJSON?.message || t('generic_failed', 'Failed'), 'error');
            });
        });
    });

    $(document).on('click', '.btn-repricing-reject', function () {
        var $btn = $(this);
        var id = $btn.data('request-id');
        var idx = $btn.data('index');
        Swal.fire({
            title: t('repricing_confirm_reject_title', 'Reject repricing?'),
            input: 'textarea',
            inputLabel: t('repricing_reject_reason', 'Rejection reason'),
            inputPlaceholder: t('reject_reason_placeholder', 'Explain the reason...'),
            inputAttributes: { maxlength: 500 },
            showCancelButton: true,
            confirmButtonText: t('repricing_reject', 'Reject'),
            cancelButtonText: t('cancel', 'Cancel'),
            confirmButtonColor: '#fb7185',
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return t('reject_reason_required', 'Rejection reason is required');
                }
            },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.post('/finance/repricing/' + id + '/reject', { rejection_note: result.value }, function (res) {
                showToast(res.message || t('repricing_rejected', 'Rejected'));
                refreshData();
                if (typeof window.refreshPengajuanNavBadge === 'function') {
                    window.refreshPengajuanNavBadge();
                }
            }).fail(function (xhr) {
                showToast(xhr.responseJSON?.message || t('generic_failed', 'Failed'), 'error');
            });
        });
    });

    function readRepricePricing($btn) {
        var raw = $btn.attr('data-pricing');
        if (raw) {
            try {
                var parsed = JSON.parse(raw);
                return {
                    price_per_cbm: parseFloat(parsed.price_per_cbm) || 0,
                    weight_price: parseFloat(parsed.weight_price) || 0,
                    extra_charge: parseFloat(parsed.extra_charge) || 0,
                };
            } catch (e) {
                // fall through
            }
        }

        return {
            price_per_cbm: parseFloat($btn.attr('data-price-cbm') || $btn.data('priceCbm') || 0) || 0,
            weight_price: parseFloat($btn.attr('data-weight-price') || $btn.data('weightPrice') || 0) || 0,
            extra_charge: parseFloat($btn.attr('data-extra') || $btn.data('extra') || 0) || 0,
        };
    }

    function currencyValue(selector) {
        var el = $(selector)[0];
        if (typeof window.getInputNumericValue === 'function') {
            return window.getInputNumericValue(el);
        }
        return parseFloat(String($(selector).val()).replace(/[^\d.-]/g, '')) || 0;
    }

    function setCurrencyInput(selector, value) {
        var el = $(selector)[0];
        if (!el) {
            return;
        }
        var num = parseFloat(value) || 0;
        if (el.inputmask) {
            el.inputmask.setValue(String(num));
        } else {
            $(el).val(num > 0 ? num : '');
        }
    }

    function setRepriceSubmitLoading(loading) {
        var $btn = $('#rpSubmitBtn');
        if (!$btn.length) {
            return;
        }
        var $form = $('#repriceForm');
        if (loading) {
            if (!$btn.data('original-html')) {
                $btn.data('original-html', $btn.html());
            }
            $btn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                + escHtml(t('repricing_submitting', 'Mengajukan…')),
            );
            $form.find('input, textarea, select, button').not($btn).prop('disabled', true);
        } else {
            $btn.prop('disabled', false).html($btn.data('original-html') || $btn.html());
            $form.find('input, textarea, select, button').not($btn).prop('disabled', false);
        }
    }

    function updatePriceHints() {
        var cbmHint = $('#rpPriceHint');
        var kgHint = $('#rpWeightHint');
        if (!cbmHint.length) {
            return;
        }
        cbmHint.text(modalPrices.cbm > 0
            ? t('repricing_autofill_cbm', 'Harga saat ini') + ': ' + fmtCurrency(modalPrices.cbm)
            : t('repricing_no_saved_cbm', 'Belum ada harga/CBM tersimpan'));
        kgHint.text(modalPrices.weight > 0
            ? t('repricing_autofill_kg', 'Harga saat ini') + ': ' + fmtCurrency(modalPrices.weight)
            : t('repricing_no_saved_kg', 'Belum ada harga/kg tersimpan'));
    }

    function shippingPreview(priceCbm, weightPrice) {
        var cbm = currentCbm;
        var gw = currentGw;
        var thresholdPerCbm = currentThreshold;
        var minCbm = currentMinCbm;
        var billableCbm = minCbm > 0 ? Math.max(cbm, minCbm) : cbm;
        var allowedWeightKg = thresholdPerCbm > 0 ? (billableCbm * thresholdPerCbm) : 0;

        if (allowedWeightKg > 0 && gw > allowedWeightKg && weightPrice > 0 && priceCbm > 0) {
            var excessKg = gw - allowedWeightKg;
            var cbmPortion = Math.round(billableCbm * priceCbm * 100) / 100;
            var weightPortion = Math.round(excessKg * weightPrice * 100) / 100;

            return {
                method: 'weight_split',
                cbmPortion: cbmPortion,
                weightPortion: weightPortion,
                subtotal: Math.round((cbmPortion + weightPortion) * 100) / 100,
                excessKg: Math.round(excessKg * 100) / 100,
                capKg: Math.round(allowedWeightKg * 100) / 100,
                billableCbm: billableCbm,
            };
        }

        if (allowedWeightKg > 0 && gw > allowedWeightKg && weightPrice > 0) {
            var weightPortionOnly = Math.round(gw * weightPrice * 100) / 100;

            return {
                method: 'weight',
                cbmPortion: 0,
                weightPortion: weightPortionOnly,
                subtotal: weightPortionOnly,
                excessKg: gw,
            };
        }

        var cbmPortionOnly = Math.round(billableCbm * priceCbm * 100) / 100;

        return {
            method: 'cbm',
            cbmPortion: cbmPortionOnly,
            weightPortion: 0,
            subtotal: cbmPortionOnly,
            excessKg: 0,
            billableCbm: billableCbm,
        };
    }

    function updateStatusBanner() {
        var $banner = $('#rpStatusBanner');
        var $badge = $('#rpStatusBadge');
        if (!currentPending) {
            $banner.addClass('d-none');
            return;
        }
        $badge.text(t('repricing_pending', 'Menunggu ACC'));
        $banner.removeClass('d-none');
    }

    function initTable() {
        table = $('#pricingTable').DataTable({
            dom: 'rt<"dt-bottom"lip>',
            ordering: true,
            pageLength: 25,
            columns: [
                { data: 'index_info' },
                { data: 'mark' },
                { data: 'item' },
                { data: 'volume_stack' },
                { data: 'price' },
                { data: 'action', orderable: false, className: 'text-nowrap' },
            ],
            language: {
                search: '',
                searchPlaceholder: 'Cari...',
                sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                emptyTable: 'Tidak ada data tersedia',
                paginate: {
                    next: '<i class="bi bi-chevron-right"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                },
            },
        });
    }

    function refreshData() {
        $.ajax({
            url: '/finance/pricing/data',
            method: 'GET',
            data: {
                search: $('#filterSearch').val(),
                start_load_date: $('#filterStartLoadDate').val(),
                end_load_date: $('#filterEndLoadDate').val(),
                show: $('#filterShow').val(),
            },
            success: function (rows) {
                table.clear().draw();
                table.rows.add(rows.map(formatRow)).draw();
            },
            error: function () {
                showToast(t('failed_load_pricing_data', 'Failed to load pricing data'), 'error');
            },
        });
    }

    function formatVolumeStack(row) {
        var coly = row.ctns != null && row.ctns !== '' ? String(row.ctns) : '0';
        var cbm = window.fmtCbm ? window.fmtCbm(row.total_cbm || 0) : Number(row.total_cbm || 0).toFixed(3);
        var gw = Number(row.total_gross_weight || 0).toFixed(2);

        return '<div class="barang-volume-stack">'
            + '<div><span class="fw-600 text-white">' + escHtml(coly) + '</span> <span class="text-muted-glass">CTNS</span></div>'
            + '<div class="barang-volume-cbm text-accent fw-700">' + cbm + ' <span class="text-muted-glass">CBM</span></div>'
            + '<div><span class="text-white">' + gw + '</span> <span class="text-muted-glass">kg</span></div>'
            + '</div>';
    }

    function formatRow(row) {
        var pending = row.pending_repricing || null;
        var indexHtml = '<div class="fw-600 text-white">' + escHtml(row.no_index) + '</div>';
        if (row.tanggal_masuk_display) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem">' + escHtml(row.tanggal_masuk_display) + '</div>';
        } else if (row.tanggal_masuk) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem">' + escHtml(formatDate(row.tanggal_masuk)) + '</div>';
        }
        if (row.marketing_name) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem"><i class="bi bi-person-badge me-1"></i>' + escHtml(row.marketing_name) + '</div>';
        }
        if (row.route_label) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem">' + escHtml(row.route_label) + '</div>';
        }
        if (row.no_po) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem">PO: ' + escHtml(row.no_po) + '</div>';
        }
        if (row.container_code) {
            indexHtml += '<div class="text-muted-glass" style="font-size:.7rem"><i class="bi bi-truck me-1"></i>' + escHtml(row.container_code) + '</div>';
        }
        if (row.invoice_id && row.invoice_no
            && (typeof window._canViewFinanceInvoice === 'undefined' || window._canViewFinanceInvoice)) {
            var invoiceLabel = row.invoice_no;
            var financeBase = (typeof window._financeIndexUrl !== 'undefined' && window._financeIndexUrl)
                ? window._financeIndexUrl
                : '/finance';
            indexHtml += '<div class="mt-1">'
                + '<a href="' + escAttr(financeBase + '?search=' + encodeURIComponent(row.invoice_no)) + '"'
                + ' class="badge-glass badge-status-loaded text-decoration-none" style="font-size:.65rem"'
                + ' title="' + escAttr(invoiceLabel) + '">'
                + '<i class="bi bi-receipt me-1"></i>' + escHtml(invoiceLabel)
                + '</a></div>';
        }
        if (pending) {
            indexHtml += '<div class="mt-1"><span class="badge-glass badge-status-pending" style="font-size:.65rem"><i class="bi bi-hourglass-split me-1"></i>'
                + escHtml(t('repricing_pending', 'Pending approval')) + '</span></div>';
        }

        var markHtml = row.marking
            ? '<span class="badge-glass badge-status-loaded">' + escHtml(row.marking) + '</span>'
            : '-';
        if (row.partai_display) {
            markHtml += '<div class="text-muted-glass" style="font-size:.7rem">' + escHtml(row.partai_display) + '</div>';
        } else {
            markHtml += '<div class="text-muted-glass" style="font-size:.7rem">' + escHtml(t('partai_not_loaded', 'Belum dimuat')) + '</div>';
        }

        var priceHtml = parseFloat(row.price_per_cbm) > 0
            ? '<div class="text-danger fw-700">' + fmtNumber(row.price_per_cbm, 0) + '</div>'
            : (parseFloat(row.weight_price) > 0
                ? '<div class="text-danger fw-700">' + fmtNumber(row.weight_price, 0) + ' /kg</div>'
                : '<div class="text-muted-glass">' + escHtml(t('repricing_not_set', 'Not set')) + '</div>');
        if (parseFloat(row.extra_charge) > 0) {
            priceHtml += '<div class="text-muted-glass" style="font-size:.7rem">+ Extra: ' + fmtNumber(row.extra_charge, 0) + '</div>';
        }
        if (pending) {
            priceHtml += '<div class="text-warning small mt-1">'
                + escHtml(t('repricing_proposed_cbm', 'Diajukan / CBM')) + ': ' + fmtNumber(pending.new_price_per_cbm, 0)
                + ' · ' + escHtml(t('repricing_proposed_weight', 'Diajukan / kg')) + ': '
                + fmtNumber(pending.new_weight_price, 0) + '</div>';
            if (parseFloat(pending.new_extra_charge) > 0) {
                priceHtml += ' + ' + fmtNumber(pending.new_extra_charge, 0);
            }
            if (pending.submitted_by) {
                priceHtml += '<div class="text-muted-glass" style="font-size:.65rem">'
                    + escHtml(t('repricing_submitted_by', 'Submitted by')) + ': ' + escHtml(pending.submitted_by) + '</div>';
            }
            if (pending.proof_url) {
                priceHtml += '<div class="mt-1"><a href="' + escAttr(pending.proof_url) + '" target="_blank" rel="noopener" class="small">'
                    + escHtml(t('repricing_view_proof', 'View proof')) + '</a></div>';
            }
        }

        var actionHtml = '';
        var canSubmit = typeof _canSubmitRepricing !== 'undefined' && _canSubmitRepricing;
        var canApprove = typeof _canApproveRepricing !== 'undefined' && _canApproveRepricing;

        if (canSubmit) {
            var pendingAttr = pending ? escAttr(JSON.stringify(pending)) : '';
            var pricingPayload = {
                price_per_cbm: (pending ? parseFloat(pending.new_price_per_cbm) : parseFloat(row.price_per_cbm)) || 0,
                weight_price: (pending && pending.new_weight_price != null
                    ? parseFloat(pending.new_weight_price)
                    : parseFloat(row.weight_price)) || 0,
                extra_charge: (pending ? parseFloat(pending.new_extra_charge) : parseFloat(row.extra_charge)) || 0,
                minimum_charge_cbm: (pending && pending.new_minimum_charge_cbm != null
                    ? parseFloat(pending.new_minimum_charge_cbm)
                    : parseFloat(row.minimum_charge_cbm)) || 0,
                weight_threshold_kg: (pending && pending.new_weight_threshold_kg != null
                    ? parseFloat(pending.new_weight_threshold_kg)
                    : parseFloat(row.weight_threshold_kg)) || 0,
            };
            var thresholdForBtn = pricingPayload.weight_threshold_kg;
            actionHtml += '<button type="button" class="btn btn-sm btn-reprice"' +
                ' data-id="' + row.id + '"' +
                ' data-index="' + escAttr(row.no_index) + '"' +
                ' data-marking="' + escAttr(row.marking || '') + '"' +
                ' data-cbm="' + row.total_cbm + '"' +
                ' data-gw="' + (row.total_gross_weight || 0) + '"' +
                ' data-threshold="' + (thresholdForBtn || 0) + '"' +
                ' data-min-cbm="' + (row.minimum_charge_cbm || 0) + '"' +
                ' data-cost-cbm="' + (row.cost_price_per_cbm || 0) + '"' +
                ' data-cost-weight="' + (row.cost_weight_price || 0) + '"' +
                ' data-pricing="' + escAttr(JSON.stringify(pricingPayload)) + '"' +
                (pending ? ' data-pending="' + pendingAttr + '"' : '') +
                '><i class="bi bi-tags me-1"></i>RE-PRICING</button>';
        }

        if (pending && canApprove) {
            actionHtml += '<div class="d-flex gap-1 mt-1 flex-nowrap">' +
                '<button type="button" class="btn btn-sm btn-accent btn-repricing-approve"' +
                ' data-request-id="' + pending.id + '"' +
                ' data-index="' + escAttr(row.no_index) + '">' +
                '<i class="bi bi-check-lg"></i></button>' +
                '<button type="button" class="btn btn-sm btn-glass btn-repricing-reject"' +
                ' data-request-id="' + pending.id + '"' +
                ' data-index="' + escAttr(row.no_index) + '">' +
                '<i class="bi bi-x-lg"></i></button></div>';
        }

        return {
            index_info: indexHtml,
            mark: markHtml,
            volume_stack: formatVolumeStack(row),
            item: '<div class="text-white small">' + escHtml(row.barang_summary || '-') + '</div>',
            price: priceHtml,
            action: actionHtml ? '<div class="d-flex flex-column gap-1">' + actionHtml + '</div>' : '',
        };
    }

    function updatePendingInfo() {
        var $info = $('#rpPendingInfo');
        var $meta = $('#rpPendingMeta');
        if (!currentPending) {
            $info.addClass('d-none');
            $meta.empty();
            return;
        }
        var lines = [];
        if (currentPending.submitted_by) {
            lines.push(t('repricing_submitted_by', 'Submitted by') + ': ' + currentPending.submitted_by);
        }
        if (currentPending.submitted_at) {
            lines.push(t('repricing_submitted_at', 'Submitted at') + ': ' + formatDateTime(currentPending.submitted_at));
        }
        if (currentPending.reason) {
            lines.push(t('repricing_reason', 'Notes / reason') + ': ' + currentPending.reason);
        }
        lines.push(t('repricing_current', 'Current') + ': ' + fmtNumber(currentPending.old_price_per_cbm, 0) + ' /CBM, '
            + fmtNumber(currentPending.old_weight_price, 0) + ' /kg');
        lines.push(t('repricing_proposed_cbm', 'Proposed / CBM') + ': ' + fmtNumber(currentPending.new_price_per_cbm, 0)
            + ' · ' + t('repricing_proposed_weight', 'Proposed / kg') + ': ' + fmtNumber(currentPending.new_weight_price, 0));
        if (currentPending.proof_url) {
            lines.push('<a href="' + escAttr(currentPending.proof_url) + '" target="_blank" rel="noopener">'
                + escHtml(t('repricing_view_proof', 'View proof')) + '</a>');
        }
        $meta.html(lines.map(function (l) {
            return l.indexOf('<a ') === 0 ? '<div>' + l + '</div>' : '<div>' + escHtml(l) + '</div>';
        }).join(''));
        $info.removeClass('d-none');
    }

    function billableCbmForPreview() {
        return currentMinCbm > 0 ? Math.max(currentCbm, currentMinCbm) : currentCbm;
    }

    function calcPreview() {
        var extra = currencyValue('#rpExtra');
        var priceCbm = currencyValue('#rpPrice');
        var weightPrice = currencyValue('#rpWeightPrice');
        var preview = shippingPreview(priceCbm, weightPrice);

        $('#rpPricingMode').val(preview.method);

        var ruleHint = t('repricing_rule_cbm', 'Penagihan normal: CBM × harga/CBM.');
        var cbmLabel = t('repricing_calc_cbm', 'CBM × Harga/CBM');
        var weightLabel = t('repricing_calc_weight', 'Berat × Harga/kg');

        if (preview.method === 'weight') {
            ruleHint = t('repricing_rule_weight', 'Berat melebihi batas: seluruh berat × harga/kg.');
            cbmLabel = t('repricing_calc_cbm', 'CBM (tidak ditagih)');
            weightLabel = t('repricing_calc_weight', 'Berat ' + currentGw.toFixed(2) + ' kg × harga/kg');
        } else if (preview.method === 'weight_split') {
            var splitBillable = preview.billableCbm != null ? preview.billableCbm : billableCbmForPreview();
            ruleHint = t('repricing_rule_weight_split', 'Berat melebihi batas CBM: CBM × harga/CBM + sisa kg × harga/kg.');
            cbmLabel = t('repricing_calc_cbm', 'CBM × Harga/CBM') + ' (' + (window.fmtCbm ? window.fmtCbm(splitBillable) : Number(splitBillable).toFixed(3)) + ' CBM, max ' + preview.capKg.toFixed(2) + ' kg)';
            weightLabel = (t('repricing_calc_excess_weight', 'Sisa berat (:kg kg) × harga/kg') || '')
                .replace(':kg', preview.excessKg.toFixed(2));
        } else {
            var billable = preview.billableCbm != null ? preview.billableCbm : billableCbmForPreview();
            cbmLabel = t('repricing_calc_cbm', 'CBM × Harga/CBM') + ' (' + (window.fmtCbm ? window.fmtCbm(billable) : Number(billable).toFixed(3)) + ' CBM)';
        }

        $('#rpRuleHint').text(ruleHint);
        $('#rpCalcCbmLabel').text(cbmLabel);
        $('#rpCalcWeightLabel').text(weightLabel);
        $('#rpCalcCbm').text(fmtCurrency(preview.cbmPortion));
        $('#rpCalcWeight').text(fmtCurrency(preview.weightPortion));
        $('#rpCalcWeightRow').toggleClass('opacity-50', preview.method === 'cbm' && preview.weightPortion <= 0);
        $('#rpCalcExtra').text(fmtCurrency(extra));
        $('#rpCalcTotal').text(fmtCurrency(preview.subtotal + extra));
    }

    function escHtml(value) {
        return $('<span>').text(value == null ? '' : value).html();
    }

    function escAttr(value) {
        return escHtml(value).replace(/"/g, '&quot;');
    }

    function formatDate(value) {
        var date = new Date(value + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function formatDateTime(value) {
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
});
