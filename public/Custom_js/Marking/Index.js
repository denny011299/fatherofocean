document.addEventListener('DOMContentLoaded', function () {
    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function tReplace(key, fallback, replacements) {
        var s = t(key, fallback);
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
        var tip = escAttr(t(key, fallback));
        return ' data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="' + tip + '" title="' + tip + '"';
    }

    function formatLastShipDate(value) {
        if (!value) return '';
        var date = new Date(value + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function fmtRp(value) {
        if (typeof window.fmtCurrency === 'function') {
            return window.fmtCurrency(value);
        }
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
    }

    function initMarkingCurrencyMask($scope) {
        if (typeof window.initCurrencyMask !== 'function') {
            return;
        }
        var root = ($scope && $scope.length) ? $scope[0] : document.getElementById('formModal');
        if (!root) {
            return;
        }
        window.initCurrencyMask(root.querySelectorAll('.mask-currency'));
    }

    function setCurrencyFieldValue($input, value) {
        if (!$input || !$input.length) {
            return;
        }
        var amount = Number(value) || 0;
        if ($input[0].inputmask) {
            $input.val(amount);
            $input.trigger('input');
            return;
        }
        $input.val(amount);
    }

    function getNumericFromInput($input) {
        if (!$input || !$input.length) {
            return 0;
        }
        if (typeof window.getInputNumericValue === 'function') {
            return window.getInputNumericValue($input[0]);
        }
        return parseFloat(String($input.val()).replace(/[^\d.,\-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
    }

    function localTodayYmd() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function effectiveStartYmd(value) {
        if (!value) return '';
        return String(value).slice(0, 10);
    }

    function pricingItemName(pricing) {
        if (!pricing) return '';
        return (pricing.pricing_item && pricing.pricing_item.name)
            || (pricing.pricingItem && pricing.pricingItem.name)
            || pricing.pricing_item_name
            || '';
    }

    function isMarkingPricingEffective(markingEffectiveStart, today) {
        var start = effectiveStartYmd(markingEffectiveStart);
        return !start || start <= today;
    }

    function comparePricingById(a, b) {
        return (Number(b.id) || 0) - (Number(a.id) || 0);
    }

    var masterPricingById = window.markingMasterPricingItems || {};

    function masterPricesForItem(itemId) {
        if (!itemId) return { volume_price: 0, gw_price: 0 };
        var master = masterPricingById[String(itemId)] || masterPricingById[itemId];
        return {
            volume_price: Number(master && master.volume_price) || 0,
            gw_price: Number(master && master.gw_price) || 0
        };
    }

    function pricingRowHasAmount(pricing) {
        if (!pricing) return false;
        var vol = Number(pricing.volume_price) || 0;
        var gw = Number(pricing.gw_price) || 0;
        return vol > 0 || gw > 0;
    }

    function pricingsByItem(pricings) {
        if (!pricings || !pricings.length) return [];
        var byItem = {};
        pricings.forEach(function (p) {
            if (!pricingRowHasAmount(p)) return;
            var itemId = p.pricing_item_id != null ? String(p.pricing_item_id) : '_none';
            var existing = byItem[itemId];
            if (!existing || comparePricingById(p, existing) < 0) {
                byItem[itemId] = p;
            }
        });
        return Object.keys(byItem).map(function (key) { return byItem[key]; })
            .sort(function (a, b) {
                return pricingItemName(a).localeCompare(pricingItemName(b), 'id', { sensitivity: 'base' });
            });
    }

    function currentEffectivePricingsByItem(pricings, markingEffectiveStart) {
        if (!isMarkingPricingEffective(markingEffectiveStart, localTodayYmd())) return [];
        return pricingsByItem(pricings);
    }

    function formatEffectiveStartLabel(value) {
        if (!value) return '';
        var date = new Date(effectiveStartYmd(value) + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return effectiveStartYmd(value);
        return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function formatAbbrevNumber(num, maxDecimals) {
        var rounded = maxDecimals > 0 ? Number(num.toFixed(maxDecimals)) : Math.round(num);
        var text = String(rounded);
        if (text.indexOf('.') !== -1) {
            text = text.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
        }
        return text.replace('.', ',');
    }

    function abbrevPrice(value) {
        var n = Number(value);
        if (!Number.isFinite(n) || n <= 0) return null;
        var abs = Math.abs(n);
        if (abs >= 1000000) {
            var jt = n / 1000000;
            var wholeJt = Math.abs(jt - Math.round(jt)) < 1e-9;
            // Keep 2 decimals for values like 5.750.000 → "5,75 Jt" (not "5,8 Jt").
            var jtDecimals = wholeJt ? 0 : (Math.abs((jt * 10) - Math.round(jt * 10)) < 1e-9 ? 1 : 2);
            return formatAbbrevNumber(jt, jtDecimals) + ' Jt';
        }
        if (abs >= 1000) {
            var rb = n / 1000;
            var wholeRb = Math.abs(rb - Math.round(rb)) < 1e-9;
            var rbDecimals = abs >= 10000 ? 0 : (wholeRb ? 0 : 1);
            return formatAbbrevNumber(rb, rbDecimals) + ' Rb';
        }
        return formatAbbrevNumber(n, 0);
    }

    function formatDisplayItemName(name) {
        var label = String(name || '').trim();
        if (!label) return '';
        return label.charAt(0).toUpperCase() + label.slice(1);
    }

    function formatPricePart(amount, unit) {
        if (amount == null || amount === '' || Number(amount) <= 0) return null;
        var short = abbrevPrice(amount);
        if (!short) return null;
        return short + ' / ' + unit;
    }

    function formatMinChargeValue(value) {
        var n = Number(value);
        if (!Number.isFinite(n) || n <= 0) return null;
        var decimals = Math.abs(n - Math.round(n)) < 1e-9 ? 0 : 2;
        return formatAbbrevNumber(n, decimals);
    }

    function parseDecimalField($input) {
        if (!$input || !$input.length) {
            return 0;
        }
        var raw = String($input.val() || '').trim().replace(',', '.');
        var n = parseFloat(raw);
        return Number.isFinite(n) && n >= 0 ? n : 0;
    }

    function setDecimalFieldValue($input, value) {
        if (!$input || !$input.length) {
            return;
        }
        var n = Number(value);
        if (!Number.isFinite(n) || n <= 0) {
            $input.val('');
            return;
        }
        $input.val(String(n));
    }

    function formatCombinedPriceLine(name, pricing) {
        var parts = [];
        var vol = formatPricePart(pricing.volume_price, 'cbm');
        var gw = formatPricePart(pricing.gw_price, 'kg');
        if (vol) parts.push(vol);
        if (gw) parts.push(gw);
        if (!parts.length) return null;
        var priceText = parts.join(' · ');
        var label = formatDisplayItemName(name);
        if (!label) return priceText;
        return label + ' : ' + priceText;
    }

    function resolveMarkingMinCharge(item) {
        if (!item) return null;
        var globalMin = Number(item.minimum_charge);
        if (Number.isFinite(globalMin) && globalMin > 0) {
            return globalMin;
        }
        var rows = currentEffectivePricingsByItem(item.pricings || [], item.effective_start);
        var mins = [];
        rows.forEach(function (p) {
            var n = Number(p.minimum_charge);
            if (Number.isFinite(n) && n > 0) {
                mins.push(n);
            }
        });
        if (!mins.length) return null;
        return Math.max.apply(null, mins);
    }

    function formatMarkingMinLine(item) {
        var value = resolveMarkingMinCharge(item);
        if (value == null) return null;
        var formatted = formatMinChargeValue(value);
        if (!formatted) return null;
        return tReplace('min_charge_short', 'min :value cbm', { value: formatted });
    }

    function escapeHtmlText(text) {
        return $('<span>').text(text).html();
    }

    function resolveInvoiceDueDays(item) {
        if (!item) return null;
        var raw = item.invoice_due_days;
        if (raw != null && raw !== '') {
            var n = parseInt(String(raw), 10);
            if (Number.isFinite(n) && n > 0) return n;
        }
        var pricings = item.pricings;
        if (!Array.isArray(pricings)) return null;
        for (var i = 0; i < pricings.length; i++) {
            var p = pricings[i];
            if (!p || p.invoice_due_days == null || p.invoice_due_days === '') continue;
            var pd = parseInt(String(p.invoice_due_days), 10);
            if (Number.isFinite(pd) && pd > 0) return pd;
        }
        return null;
    }

    function formatInvoiceDueLine(item) {
        var days = resolveInvoiceDueDays(item);
        if (days == null) return null;
        return tReplace('invoice_due_short', 'Due: :days d', { days: String(days) });
    }

    function formatPriceLines(item) {
        var priceLines = [];
        var rows = pricingsByItem(item.pricings || []);
        if (rows.length) {
            rows.forEach(function (p) {
                var line = formatCombinedPriceLine(pricingItemName(p), p);
                if (line) priceLines.push(line);
            });
            if (item.effective_start && !isMarkingPricingEffective(item.effective_start, localTodayYmd())) {
                var startsLabel = formatEffectiveStartLabel(item.effective_start);
                if (startsLabel) {
                    priceLines.push(tReplace('pricing_starts_on', 'mulai :date', { date: startsLabel }));
                }
            }
        } else if (isMarkingPricingEffective(item.effective_start, localTodayYmd())) {
            var parts = [];
            var vol = formatPricePart(item.display_volume_price, 'cbm');
            var gw = formatPricePart(item.display_gw_price, 'kg');
            if (vol) parts.push(vol);
            if (gw) parts.push(gw);
            if (parts.length) priceLines.push(parts.join(' · '));
        }
        return priceLines;
    }

    function formatPriceCell(priceLines, item) {
        var allLines = (priceLines || []).slice();
        if (item) {
            var dueLine = formatInvoiceDueLine(item);
            if (dueLine) allLines.push(dueLine);
            var minLine = formatMarkingMinLine(item);
            if (minLine) allLines.push(minLine);
        }
        if (!allLines.length) {
            return '<span class="small text-muted" style="font-size:.75rem">-</span>';
        }
        var html = '<div class="marking-price-cell small text-muted">';
        html += '<ul class="marking-price-list mb-0">';
        allLines.forEach(function (line) {
            html += '<li>' + escapeHtmlText(line) + '</li>';
        });
        html += '</ul>';
        html += '</div>';
        return html;
    }

    var PRICE_COL_INDEX = 5;
    var PRICE_STORAGE_KEY = 'marking_index_show_prices';

    function nonVipPriorityBadge(item) {
        if (item.last_shipping_at) {
            var label = formatLastShipDate(item.last_shipping_at);
            return '<span class="badge-glass badge-non-vip"' + tipAttrs('last_ship_date', 'Last shipping date') + '>'
                + $('<span>').text(label).html() + '</span>';
        }
        return '<span class="badge-glass badge-non-vip"' + tipAttrs('never_shipped', 'Never shipped') + '>'
            + $('<span>').text(t('never_shipped', 'Belum pernah kirim')).html() + '</span>';
    }

    var mode = 1;
    var table;
    var allData = [];
    var ktpObjectUrl = null;

    var periodPresetUi = window.DatePresetFilter
        ? window.DatePresetFilter.init({
            presetSelector: '#filterPeriod',
            customRowSelector: '#filterPeriodCustomRow',
            fromSelector: '#filterPeriodFrom',
            toSelector: '#filterPeriodTo',
            onChange: function () {
                refreshData();
            },
        })
        : null;
    window.__markingPeriodPresetUi = periodPresetUi;

    function periodApiParams() {
        if (window.DatePresetFilter) {
            return window.DatePresetFilter.getApiParams(
                '#filterPeriod',
                '#filterPeriodFrom',
                '#filterPeriodTo',
                'period',
            );
        }
        var period = $('#filterPeriod').val() || '';
        return period ? { period: period } : {};
    }

    inisialisasi();
    initPriceToggle();
    initKtpPhotoUi();
    initMarkingRelationUi();
    refreshData();

    // ── Filter events ──
    var debounceTimer;
    $('#filterSearch').on('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyFilters, 250);
    });
    $('#filterSort').on('change', applyFilters);
    $('#filterMarketing').on('change', refreshData);
    $('#filterPeriodFrom, #filterPeriodTo').on('input', function () {
        if (($('#filterPeriod').val() || '') !== 'custom') {
            return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refreshData, 400);
    });

    $(document).on('click', '.js-clear-period-pill', function () {
        if (periodPresetUi) {
            periodPresetUi.clearDates();
        } else {
            $('#filterPeriod').val('');
        }
        refreshData();
    });

    $('#btnResetFilter').on('click', function () {
        $('#filterSearch').val('');
        $('#filterMarketing').val('');
        if (periodPresetUi) {
            periodPresetUi.reset();
        } else {
            $('#filterPeriod').val('');
        }
        $('#filterSort').val('code-asc');
        refreshData();
    });

    function buildMarkingExportUrl(format) {
        if (typeof markingExportUrl === 'undefined' || !markingExportUrl) {
            return '#';
        }
        var params = new URLSearchParams(Object.assign({
            sales_id: $('#filterMarketing').val() || '',
            search: $('#filterSearch').val() || '',
            sort: $('#filterSort').val() || 'code-asc',
            show_prices: '1',
            format: format || 'xlsx',
        }, periodApiParams()));
        return markingExportUrl + '?' + params.toString();
    }

    $(document).on('click', '#btnExportMarking, #btnExportMarkingCsv', function (e) {
        e.preventDefault();
        var format = $(this).data('format') || 'xlsx';
        window.location.href = buildMarkingExportUrl(format);
    });

    // ── NIK check (debounced) ──
    var nikTimer;
    $('#inputNik').on('input', function () {
        clearTimeout(nikTimer);
        var nik = $(this).val().trim();
        var $status = $('#nikStatus');
        if (!nik) { $status.html(''); return; }
        var relatedId = relatedMarkingIdForSave();
        if (relatedId) {
            $status.html('<span style="color:var(--text-muted)"><i class="bi bi-info-circle me-1"></i>'
                + t('nik_skipped_relation', 'NIK tidak dicek (relasi marking)') + '</span>');
            return;
        }
        $status.html('<span style="color:var(--text-muted)"><i class="bi bi-hourglass-split me-1"></i>Memeriksa...</span>');
        var excludeId = mode === 2 ? $('#formModal').attr('data-edit-id') : '';
        nikTimer = setTimeout(function () {
            $.get('/marking/check-nik', { nik: nik, exclude_id: excludeId, related_marking_id: relatedId }, function (res) {
                if (res.exists) {
                    $status.html('<span style="color:var(--danger,#fb7185)"><i class="bi bi-exclamation-triangle me-1"></i>NIK sudah digunakan oleh marking: ' + (res.match ? (res.match.raw_code || res.match.code) : '-') + '</span>');
                } else {
                    $status.html('<span style="color:var(--success,#34d399)"><i class="bi bi-check-circle me-1"></i>NIK tersedia</span>');
                }
            });
        }, 500);
    });

    // ── Dynamic pricing rows ──
    $('#btnAddPricing').on('click', function () {
        addPricingRow();
    });

    $(document).on('click', '.btnRemovePricing', function () {
        $(this).closest('.pricing-row').remove();
    });

    $(document).on('change', '.pricing-item-select', function () {
        prefillPricingRowFromMasterIfEmpty($(this).closest('.pricing-row'));
    });

    // ── CRUD events ──
    $(document).on('click', '.btnAdd', function () {
        mode = 1;
        $('#formModalTitle').html('<i class="bi bi-bookmark-star me-2 text-accent"></i>Tambah Marking');
        resetForm();
        $('#formModal').removeAttr('data-related-id data-related-label');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $('#dataForm').on('submit', function (e) {
        e.preventDefault();
        simpanData();
    });

    $(document).on('click', '.btn_edit', function () {
        var data = table.row($(this).parents('tr')).data();
        mode = 2;
        $('#formModalTitle').html('<i class="bi bi-bookmark-star me-2 text-accent"></i>Edit Marking');
        resetForm();
        populateForm(data);
        $('#formModal')
            .attr('data-edit-id', data.id)
            .attr('data-related-id', data.related_marking_id || '')
            .attr('data-related-label', data.related_marking_label || '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('formModal')).show();
    });

    $(document).on('click', '.btn_delete', function () {
        var data = table.row($(this).parents('tr')).data();
        Swal.fire({
            title: (window.__t && window.__t.delete_marking_title) || 'Hapus Marking?',
            text: ((window.__t && window.__t.delete_marking_text) || 'Marking ":name" akan dihapus permanen.').replace(':name', data.raw_code || ''),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280',
            confirmButtonText: (window.__t && window.__t.confirm_delete) || 'Ya, Hapus',
            cancelButtonText: (window.__t && window.__t.cancel) || 'Batal'
        }).then(function (result) {
            if (result.isConfirmed) hapusData(data.id);
        });
    });

    function inisialisasi() {
        table = $('#markingTable').DataTable({
            dom: 'rt<"dt-bottom"lip>',
            ordering: false,
            pageLength: 25,
            autoWidth: false,
            columnDefs: [
                { targets: 0, width: '9%' },
                { targets: 1, width: '7%' },
                { targets: 2, width: '16%' },
                { targets: 3, width: '34%', className: 'alamat-wrap' },
                { targets: 4, width: '13%' },
                { targets: 5, width: '18%', className: 'col-price' },
                { targets: 6, width: '120px', className: 'text-center' }
            ],
            columns: [
                { data: "branch_col" },
                { data: "marketing_col" },
                { data: "code_col" },
                { data: "alamat_col", className: 'alamat-wrap' },
                { data: "phone_col" },
                { data: "price_col", visible: false, className: 'col-price' },
                { data: "action", className: 'text-center' }
            ],
            language: {
                search: '', searchPlaceholder: 'Cari...',
                sLengthMenu: '_MENU_ per halaman',
                info: '_START_ - _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                emptyTable: '<div style="padding:2rem;text-align:center;color:var(--text-muted)"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>Tidak ada marking ditemukan</div>',
                paginate: { next: '&raquo;', previous: '&laquo;' }
            }
        });
        table.on('draw.dt', function () {
            if (window.initBsTooltips) {
                window.initBsTooltips('#markingTable');
            }
        });
    }

    function setPriceColumnVisible(visible, adjust) {
        if (!table) {
            return;
        }
        table.column(PRICE_COL_INDEX).visible(visible);
        if (adjust) {
            table.columns.adjust().draw(false);
        }
    }

    function initPriceToggle() {
        var $toggle = $('#toggleShowPrices');
        if (!$toggle.length) {
            return;
        }

        var showPrices = false;
        try {
            showPrices = localStorage.getItem(PRICE_STORAGE_KEY) === '1';
        } catch (e) {
            showPrices = false;
        }

        $toggle.prop('checked', showPrices);
        setPriceColumnVisible(showPrices, true);

        $toggle.on('change', function () {
            var on = $(this).is(':checked');
            try {
                localStorage.setItem(PRICE_STORAGE_KEY, on ? '1' : '0');
            } catch (err) { /* ignore */ }
            setPriceColumnVisible(on, true);
        });
    }

    function refreshData() {
        $.ajax({
            url: '/marking/data',
            method: 'get',
            data: Object.assign({
                sales_id: $('#filterMarketing').val() || '',
            }, periodApiParams()),
            success: function (e) {
                allData = e.map(function (item) {
                    item.raw_code = item.raw_code || item.code;
                    item.raw_description = item.description || '';
                    item.customer_name_raw = item.customer_name || (item.customer ? item.customer.name : '');
                    item.customer_username = item.customer ? (item.customer.username || '') : '';
                    item.customer_phone_raw = item.customer_phone || '-';
                    return item;
                });
                applyFilters();
            },
            error: function (xhr) {
                console.error('Gagal load marking:', xhr);
                allData = [];
                applyFilters();
                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    || t('load_failed', 'Gagal memuat data marking. Coba muat ulang halaman.');
                if (typeof notifikasi === 'function') {
                    notifikasi('error', t('error', 'Error'), msg);
                }
            }
        });
    }

    function applyFilters() {
        var searchTerm = ($('#filterSearch').val() || '').toLowerCase().trim();
        var sortVal = $('#filterSort').val() || 'code-asc';
        var parts = sortVal.split('-');
        var sortField = parts[0];
        var sortDir = parts[1];

        var filtered = allData.filter(function (item) {
            if (searchTerm) {
                var searchable = [
                    item.raw_code,
                    item.customer_name_raw,
                    item.customer_username,
                    item.customer_phone_raw,
                    item.raw_description,
                    item.email || '',
                    item.address_1 || '',
                    item.address_2 || '',
                    item.phone_hp || '',
                    item.fax || '',
                    item.branch_name || '',
                    item.sales_name || (item.sales ? item.sales.name : '') || '',
                    item.contact_person || '',
                    item.nik || ''
                ].join(' ').toLowerCase();

                var terms = searchTerm.split(/\s+/);
                for (var i = 0; i < terms.length; i++) {
                    if (searchable.indexOf(terms[i]) === -1) return false;
                }
            }
            return true;
        });

        filtered.sort(function (a, b) {
            var va, vb;
            if (sortField === 'code') { va = (a.raw_code || '').toLowerCase(); vb = (b.raw_code || '').toLowerCase(); }
            else if (sortField === 'name') { va = a.customer_name_raw.toLowerCase(); vb = b.customer_name_raw.toLowerCase(); }
            else { va = ''; vb = ''; }

            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        var rows = filtered.map(function (item) {
            var priorityBadge = item.priority === 'vip'
                ? '<span class="badge-glass badge-status-vip">' + $('<span>').text(t('priority_vip', 'VIP')).html() + '</span>'
                : nonVipPriorityBadge(item);

            var branchName = item.branch_name && item.branch_name !== '-'
                ? item.branch_name
                : '-';

            var salesName = item.sales_name || (item.sales && item.sales.name) || '';
            var marketingDisplay = salesName ? salesName : '\u2014';

            var addr = [item.address_1, item.address_2].filter(Boolean).join(', ') || '-';

            var waBtn = '';
            if (item.phone_hp) {
                var waPhone = formatWaPhone(item.phone_hp);
                waBtn = '<a class="btn btn-glass btn-sm" href="https://wa.me/' + waPhone + '" target="_blank" style="color:#34d399"' + tipAttrs('tooltip_whatsapp', 'WhatsApp') + '><i class="bi bi-whatsapp"></i></a>';
            }

            return {
                id: item.id,
                related_marking_id: item.related_marking_id || '',
                related_marking_label: item.related_marking_label || '',
                sales_id: item.sales_id,
                raw_code: item.raw_code,
                raw_description: item.raw_description,
                is_active: item.is_active,
                customer_name: item.customer_name || item.customer_name_raw || '',
                customer_name_raw: item.customer_name_raw,
                contact_person: item.contact_person || '',
                email: item.email || '',
                pic: item.pic || '',
                priority: item.priority || 'non_vip',
                last_shipping_at: item.last_shipping_at || null,
                branch_id: item.branch_id || '',
                address_1: item.address_1 || '',
                address_2: item.address_2 || '',
                phone_hp: item.phone_hp || '',
                fax: item.fax || '',
                phone_02: item.phone_02 || '',
                wa_name: item.wa_name || '',
                nik: item.nik || '',
                nik_name: item.nik_name || '',
                ktp_photo_url: item.ktp_photo_url || null,
                ktp_photo_path: item.ktp_photo_path || null,
                passcode: item.passcode || '',
                effective_start: item.effective_start || null,
                invoice_due_days: item.invoice_due_days ?? 0,
                max_weight_kg: item.max_weight_kg ?? 0,
                minimum_charge: item.minimum_charge ?? '',
                pricing_is_effective: item.pricing_is_effective !== false,
                pricings: item.pricings || [],

                branch_col: '<span class="badge-glass" style="font-size:.78rem">' + highlightMatch(branchName, searchTerm) + '</span>',
                marketing_col: '<span class="small" style="color:var(--text-secondary)">' + highlightMatch(marketingDisplay, searchTerm) + '</span>',
                code_col: '<div class="fw-700" style="color:var(--text-primary)">' + highlightMatch(item.raw_code, searchTerm) + '</div>'
                    + '<div class="small" style="color:var(--text-muted)">' + highlightMatch(item.customer_name_raw, searchTerm) + '</div>'
                    + priorityBadge,
                alamat_col: '<span class="small" style="color:var(--text-secondary)">' + highlightMatch(addr, searchTerm) + '</span>',
                phone_col: '<span style="color:var(--text-secondary);font-size:.84rem">'
                    + '<i class="bi bi-telephone me-1" style="font-size:.7rem;opacity:.5"></i>'
                    + highlightMatch(item.phone_hp || '-', searchTerm) + '</span>',
                price_col: formatPriceCell(formatPriceLines(item), item),
                action: (function () {
                    var btns = '';
                    if (typeof _canEditMarking !== 'undefined' && _canEditMarking) {
                        btns += '<a class="btn btn-glass btn-sm btn_edit" href="javascript:void(0);"' + tipAttrs('tooltip_edit', 'Ubah') + '><i class="bi bi-pencil"></i></a>';
                    }
                    btns += waBtn;
                    if (typeof _canDeleteMarking !== 'undefined' && _canDeleteMarking) {
                        btns += '<a class="btn btn-glass btn-sm text-danger btn_delete" href="javascript:void(0);"' + tipAttrs('tooltip_delete', 'Hapus') + '><i class="bi bi-trash"></i></a>';
                    }
                    return '<div class="d-flex gap-1 justify-content-center">' + btns + '</div>';
                })()
            };
        });

        table.clear().rows.add(rows).draw();

        $('#totalCount').html('<i class="bi bi-hash me-1"></i>' + filtered.length + ' dari ' + allData.length + ' marking');
        renderActiveFilters(searchTerm, $('#filterMarketing').val());
    }

    function formatWaPhone(phone) {
        var digits = phone.replace(/\D/g, '');
        if (digits.substring(0, 2) === '08') {
            digits = '62' + digits.substring(1);
        } else if (digits.substring(0, 1) === '8') {
            digits = '62' + digits;
        }
        return digits;
    }

    function highlightMatch(text, searchTerm) {
        if (!searchTerm || !text || text === '-') return text;
        var terms = searchTerm.split(/\s+/).filter(Boolean);
        var result = text;
        for (var i = 0; i < terms.length; i++) {
            var escaped = terms[i].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var regex = new RegExp('(' + escaped + ')', 'gi');
            result = result.replace(regex, '<mark style="background:rgba(var(--accent-rgb),.25);color:var(--text-primary);padding:0 2px;border-radius:3px">$1</mark>');
        }
        return result;
    }

    function periodFilterLabel() {
        var val = $('#filterPeriod').val() || '';
        if (!val) {
            return '';
        }
        if (val === 'custom' && window.DatePresetFilter) {
            var from = $('#filterPeriodFrom').val() || '';
            var to = $('#filterPeriodTo').val() || '';
            if (from || to) {
                return from && to ? from + ' – ' + to : (from || to);
            }
        }
        return window.DatePresetFilter
            ? window.DatePresetFilter.presetLabel(val)
            : val;
    }

    function renderActiveFilters(searchTerm, marketingId) {
        var container = document.getElementById('activeFilters');
        var pills = [];
        var periodVal = $('#filterPeriod').val() || '';
        var periodLabel = periodFilterLabel();

        if (periodVal && periodLabel) {
            pills.push('<span class="filter-pill"><i class="bi bi-calendar-range"></i> ' + periodLabel
                + '<button type="button" class="js-clear-period-pill">&times;</button></span>');
        }

        if (marketingId) {
            var marketingLabel = $('#filterMarketing option:selected').text();
            pills.push('<span class="filter-pill"><i class="bi bi-person-badge"></i> ' + marketingLabel
                + '<button type="button" onclick="document.getElementById(\'filterMarketing\').value=\'\';$(\'#filterMarketing\').trigger(\'change\');">&times;</button></span>');
        }

        if (searchTerm) {
            pills.push('<span class="filter-pill"><i class="bi bi-search"></i> "' + searchTerm + '"'
                + '<button type="button" onclick="document.getElementById(\'filterSearch\').value=\'\';$(\'#filterSearch\').trigger(\'input\');">&times;</button></span>');
        }

        container.innerHTML = pills.join('');
    }

    function revokeKtpObjectUrl() {
        if (ktpObjectUrl) {
            URL.revokeObjectURL(ktpObjectUrl);
            ktpObjectUrl = null;
        }
    }

    function toAbsoluteAssetUrl(url) {
        if (!url) {
            return null;
        }
        url = String(url).trim();
        if (!url) {
            return null;
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        if (url.indexOf('//') === 0) {
            return (window.location.protocol || 'https:') + url;
        }
        var origin = window.location.origin || '';
        if (url.charAt(0) === '/') {
            return origin + url;
        }
        return origin + '/' + url;
    }

    function buildKtpPhotoUrlFromPath(photoPath) {
        if (!photoPath) {
            return null;
        }
        var path = String(photoPath).replace(/\\/g, '/').replace(/^\/+/, '');
        if (!path) {
            return null;
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        if (path.indexOf('public/') === 0) {
            path = path.slice(7);
        }
        if (path.indexOf('storage/') === 0) {
            return toAbsoluteAssetUrl('/' + path);
        }
        return toAbsoluteAssetUrl('/storage/' + path);
    }

    function resolveKtpPhotoUrl(data) {
        data = data || {};
        if (data.ktp_photo_url && String(data.ktp_photo_url).trim()) {
            return toAbsoluteAssetUrl(String(data.ktp_photo_url).trim());
        }
        return buildKtpPhotoUrlFromPath(data.ktp_photo_path);
    }

    function syncKtpPhotoPreview(src, options) {
        options = options || {};
        var $upload = $('#ktpPhotoUpload');
        var $preview = $upload.find('.marking-ktp-photo-preview');
        var $empty = $upload.find('.marking-ktp-photo-empty');
        var $btnRemove = $upload.find('.btn-remove-marking-ktp-photo');
        var $viewLink = $('#ktpPhotoViewLink');
        var hasPhoto = !!src;
        var previousSrc = $preview.attr('src');

        if (previousSrc && previousSrc.indexOf('blob:') === 0) {
            URL.revokeObjectURL(previousSrc);
        }

        $preview.off('error.ktpPhoto').on('error.ktpPhoto', function () {
            var failedSrc = $(this).attr('src') || '';
            if (failedSrc.indexOf('blob:') === 0) {
                $(this).off('error.ktpPhoto');
                syncKtpPhotoPreview(null);
                return;
            }
            var fallback = buildKtpPhotoUrlFromPath($upload.attr('data-ktp-photo-path'))
                || ($upload.data('serverPhotoUrl') || null);
            if (fallback && fallback !== failedSrc) {
                $(this).attr('src', fallback);
                return;
            }
            $(this).off('error.ktpPhoto');
            syncKtpPhotoPreview(null);
        });

        if (hasPhoto) {
            var displaySrc = src.indexOf('blob:') === 0 ? src : (toAbsoluteAssetUrl(src) || src);
            if (src.indexOf('blob:') !== 0) {
                $upload.data('serverPhotoUrl', displaySrc);
            }
            $preview.attr('src', displaySrc).removeClass('d-none');
            $empty.addClass('d-none');
            $upload.addClass('has-photo');
            $btnRemove.removeClass('d-none');
            if (options.showViewLink && src.indexOf('blob:') !== 0) {
                $viewLink.attr('href', displaySrc).removeClass('d-none');
            } else {
                $viewLink.addClass('d-none').attr('href', '#');
            }
        } else {
            $preview.removeAttr('src').addClass('d-none');
            $empty.removeClass('d-none');
            $upload.removeClass('has-photo');
            $btnRemove.addClass('d-none');
            $upload.removeAttr('data-ktp-photo-path').removeData('serverPhotoUrl');
            $viewLink.addClass('d-none').attr('href', '#');
        }
    }

    function handleKtpPhotoSelected(input) {
        var file = input.files && input.files[0];
        revokeKtpObjectUrl();
        $('#removeKtpPhoto').val('0');
        if (!file) {
            syncKtpPhotoPreview(null);
            return;
        }

        ktpObjectUrl = URL.createObjectURL(file);
        syncKtpPhotoPreview(ktpObjectUrl);
    }

    function resetKtpPhotoUi() {
        revokeKtpObjectUrl();
        $('#inputKtpPhoto').val('');
        $('#removeKtpPhoto').val('0');
        syncKtpPhotoPreview(null);
    }

    function initKtpPhotoUi() {
        var $upload = $('#ktpPhotoUpload');
        if (!$upload.length) {
            return;
        }

        $upload.on('click', '.marking-ktp-photo-box', function (e) {
            if ($(e.target).closest('.btn-remove-marking-ktp-photo').length) {
                return;
            }
            document.getElementById('inputKtpPhoto').click();
        });

        $upload.on('keydown', '.marking-ktp-photo-box', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                document.getElementById('inputKtpPhoto').click();
            }
        });

        $('#inputKtpPhoto').on('change', function () {
            var input = this;
            handleKtpPhotoSelected(input);
        });

        $upload.on('click', '.btn-remove-marking-ktp-photo', function (e) {
            e.preventDefault();
            e.stopPropagation();
            revokeKtpObjectUrl();
            $('#inputKtpPhoto').val('');
            $('#removeKtpPhoto').val('1');
            syncKtpPhotoPreview(null);
        });
    }

    function resetForm() {
        $('#dataForm')[0].reset();
        $('#selSales').val('').trigger('change.select2');
        $('#selPriority').val('non_vip');
        $('#selBranch').val('');
        $('[name="invoice_due_days"]').val(0);
        $('[name="max_weight_kg"]').val(0);
        $('#pricingContainer').empty();
        $('#nikStatus').html('');
        $('.is-invalid').removeClass('is-invalid');
        resetKtpPhotoUi();
        resetRelatedMarkingField();
    }

    function purgeSelect2Artifacts($sel, namespace) {
        if (!$sel || !$sel.length) {
            return;
        }
        if (namespace) {
            $sel.off('.' + namespace);
        }
        if ($sel.data('select2')) {
            try {
                $sel.select2('destroy');
            } catch (e) { /* ignore stale instance */ }
        } else if ($sel.hasClass('select2-hidden-accessible')) {
            try {
                $sel.select2('destroy');
            } catch (e) { /* ignore stale instance */ }
        }
        $sel.siblings('.select2-container').remove();
        $sel.parent().find('> .select2-container').remove();
        $sel.removeClass('select2-hidden-accessible');
        $sel.removeAttr('data-select2-id aria-hidden tabindex');
        $sel.removeData('select2');
    }

    function destroySelect2Instance($sel, namespace) {
        purgeSelect2Artifacts($sel, namespace);
    }

    function bindSelect2OpenQuery($sel, namespace) {
        var evt = namespace ? ('select2:open.' + namespace) : 'select2:open';
        $sel.off(evt).on(evt, function () {
            var select2 = $sel.data('select2');
            if (!select2 || !select2.dropdown || !select2.dropdown.$search) {
                return;
            }
            window.setTimeout(function () {
                select2.dropdown.$search.trigger('input');
            }, 0);
        });
    }

    var relatedMarkingSearchTimer = null;
    var relatedMarkingSearchXhr = null;
    var relatedMarkingSuppressSearch = false;

    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    function relatedMarkingOptionsUrl() {
        var url = '/marking/options';
        if (mode === 2) {
            var editId = $('#formModal').attr('data-edit-id');
            if (editId) {
                url += '?exclude_id=' + encodeURIComponent(editId);
            }
        }
        return url;
    }

    function relatedMarkingIdForSave() {
        if (!$('#inputRelatedMarkingId').length) {
            return '';
        }
        var val = $('#inputRelatedMarkingId').val();
        if (val !== null && val !== undefined && String(val).trim() !== '') {
            return String(val).trim();
        }
        return '';
    }

    function updateRelatedMarkingSelectedDisplay() {
        var id = relatedMarkingIdForSave();
        var $display = $('#relatedMarkingSelected');
        if (!id) {
            $display.empty();
            return;
        }
        var label = String($('#relatedMarkingSearch').val() || '').trim() || ('#' + id);
        $display.html('<i class="bi bi-check2-circle me-1"></i>' + escHtml(label));
    }

    function resetRelatedMarkingField() {
        relatedMarkingSuppressSearch = true;
        $('#inputRelatedMarkingId').val('');
        $('#relatedMarkingSearch').val('');
        $('#relatedMarkingResults').addClass('d-none').empty();
        $('#relatedMarkingSelected').empty();
        relatedMarkingSuppressSearch = false;
    }

    function setRelatedMarkingSelection(id, label) {
        var idStr = id ? String(id).trim() : '';
        var labelStr = String(label || '').trim();
        relatedMarkingSuppressSearch = true;
        $('#inputRelatedMarkingId').val(idStr);
        $('#relatedMarkingSearch').val(labelStr);
        $('#relatedMarkingResults').addClass('d-none').empty();
        relatedMarkingSuppressSearch = false;
        updateRelatedMarkingSelectedDisplay();
        $('#formModal')
            .attr('data-related-id', idStr)
            .attr('data-related-label', labelStr);
    }

    function clearRelatedMarkingSelection() {
        setRelatedMarkingSelection('', '');
        $('#nikStatus').html('');
    }

    function hideRelatedMarkingResults() {
        $('#relatedMarkingResults').addClass('d-none');
    }

    function fetchRelatedMarkingOptions(query) {
        if (relatedMarkingSearchXhr && relatedMarkingSearchXhr.readyState !== 4) {
            relatedMarkingSearchXhr.abort();
        }
        var url = relatedMarkingOptionsUrl();
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        relatedMarkingSearchXhr = $.ajax({
            url: url + sep + 'q=' + encodeURIComponent(query || ''),
            dataType: 'json',
        }).done(function (data) {
            renderRelatedMarkingResults((data && data.results) ? data.results : []);
        }).fail(function (xhr) {
            if (xhr.statusText === 'abort') {
                return;
            }
            renderRelatedMarkingResults([]);
        });
    }

    function renderRelatedMarkingResults(results) {
        var $list = $('#relatedMarkingResults');
        $list.empty();
        if (!results || !results.length) {
            $list.append(
                '<div class="list-group-item text-muted small">' + escHtml(t('no_results', 'Tidak ada hasil')) + '</div>'
            );
            $list.removeClass('d-none');
            return;
        }
        results.forEach(function (item) {
            var id = item.id;
            var text = item.text || '';
            $('<button type="button" class="list-group-item list-group-item-action"></button>')
                .attr('data-id', id)
                .attr('data-text', text)
                .text(text)
                .appendTo($list);
        });
        $list.removeClass('d-none');
    }

    function onRelatedMarkingPicked(id, text) {
        setRelatedMarkingSelection(id, text);
        hideRelatedMarkingResults();
        if (!id) {
            return;
        }
        if (mode === 1) {
            applyRelatedMarkingTemplate(id);
        } else {
            $('#nikStatus').html('<span style="color:var(--text-muted)"><i class="bi bi-info-circle me-1"></i>'
                + t('nik_skipped_relation', 'NIK tidak dicek (relasi marking)') + '</span>');
        }
    }

    function setupRelatedMarkingOnModalShown() {
        var $modal = $('#formModal');
        var relatedId = String($modal.attr('data-related-id') || '').trim();
        var relatedLabel = String($modal.attr('data-related-label') || '').trim();

        resetRelatedMarkingField();
        if (relatedId) {
            setRelatedMarkingSelection(relatedId, relatedLabel || ('#' + relatedId));
        }

        if (relatedId && mode === 2) {
            $('#nikStatus').html('<span style="color:var(--text-muted)"><i class="bi bi-info-circle me-1"></i>'
                + t('nik_skipped_relation', 'NIK tidak dicek (relasi marking)') + '</span>');
        }
    }

    function patchMarkingRelatedInTable(saved) {
        if (!saved || saved.id == null) {
            return;
        }

        var id = saved.id;
        var relatedId = saved.related_marking_id != null && saved.related_marking_id !== ''
            ? String(saved.related_marking_id)
            : '';
        var relatedLabel = relatedId ? (saved.related_marking_label || '') : '';

        allData.forEach(function (item) {
            if (item.id === id) {
                item.related_marking_id = relatedId ? parseInt(relatedId, 10) : null;
                item.related_marking_label = relatedLabel;
            }
        });

        if (!table) {
            return;
        }

        table.rows().every(function () {
            var row = this.data();
            if (row && row.id === id) {
                row.related_marking_id = relatedId;
                row.related_marking_label = relatedLabel;
                this.data(row);
            }
        });
    }

    function initMarkingRelationUi() {
        if (!$('#inputRelatedMarkingId').length) {
            return;
        }

        $('#relatedMarkingSearch')
            .off('.markingRelation')
            .on('input.markingRelation', function () {
                if (relatedMarkingSuppressSearch) {
                    return;
                }
                var q = $(this).val().trim();
                var savedLabel = String($('#formModal').attr('data-related-label') || '').trim();
                if (relatedMarkingIdForSave() && q !== savedLabel) {
                    $('#inputRelatedMarkingId').val('');
                    $('#relatedMarkingSelected').empty();
                }
                clearTimeout(relatedMarkingSearchTimer);
                relatedMarkingSearchTimer = setTimeout(function () {
                    fetchRelatedMarkingOptions(q);
                }, 300);
            })
            .on('focus.markingRelation', function () {
                if (relatedMarkingSuppressSearch) {
                    return;
                }
                fetchRelatedMarkingOptions($(this).val().trim());
            });

        $('#btnClearRelatedMarking')
            .off('.markingRelation')
            .on('click.markingRelation', function (e) {
                e.preventDefault();
                clearRelatedMarkingSelection();
            });

        $('#relatedMarkingResults')
            .off('.markingRelation')
            .on('click.markingRelation', '.list-group-item-action', function (e) {
                e.preventDefault();
                onRelatedMarkingPicked($(this).attr('data-id'), $(this).attr('data-text') || $(this).text());
            });

        $(document)
            .off('click.markingRelationOutside')
            .on('click.markingRelationOutside', function (e) {
                if (!$(e.target).closest('.marking-relation-picker').length) {
                    hideRelatedMarkingResults();
                }
            });

        $('#formModal')
            .off('shown.bs.modal.markingRelation hidden.bs.modal.markingRelation')
            .on('shown.bs.modal.markingRelation', function () {
                setupRelatedMarkingOnModalShown();
            })
            .on('hidden.bs.modal.markingRelation', function () {
                resetRelatedMarkingField();
                $(this).removeAttr('data-related-id data-related-label');
            });
    }

    function applyRelatedMarkingSelectValue(relatedId, data) {
        data = data || {};
        if (!relatedId) {
            return;
        }
        var label = data.related_marking_label || data.related_marking_text || ('#' + relatedId);
        setRelatedMarkingSelection(String(relatedId), label);
        if (mode === 2) {
            $('#nikStatus').html('<span style="color:var(--text-muted)"><i class="bi bi-info-circle me-1"></i>'
                + t('nik_skipped_relation', 'NIK tidak dicek (relasi marking)') + '</span>');
        }
    }

    function applyRelatedMarkingTemplate(id) {
        $.get('/marking/' + id + '/template', function (tpl) {
            populateForm(tpl, { skipCode: true });
            applyRelatedMarkingSelectValue(String(id), { related_marking_label: tpl.raw_code || tpl.code || ('#' + id) });
            $('[name="code"]').focus();
            $('#nikStatus').html('<span style="color:var(--text-muted)"><i class="bi bi-info-circle me-1"></i>'
                + t('nik_skipped_relation', 'NIK tidak dicek (relasi marking)') + '</span>');
        }).fail(function () {
            notifikasi('error', 'Gagal', t('load_failed', 'Gagal memuat data relasi marking.'));
        });
    }

    function populateForm(data, options) {
        options = options || {};
        if (!options.skipCode) {
            $('[name="code"]').val(data.raw_code || data.code || '');
        } else {
            $('[name="code"]').val('');
        }
        $('[name="description"]').val(data.raw_description || data.description || '');
        $('[name="customer_name"]').val(data.customer_name || data.customer_name_raw || '');
        $('#selSales').val(data.sales_id || '').trigger('change.select2');
        $('[name="contact_person"]').val(data.contact_person || '');
        $('[name="email"]').val(data.email || '');
        $('[name="pic"]').val(data.pic || '');
        $('#selBranch').val(data.branch_id || '');
        $('[name="address_1"]').val(data.address_1 || '');
        $('[name="address_2"]').val(data.address_2 || '');
        $('[name="phone_hp"]').val(data.phone_hp || '');
        $('[name="fax"]').val(data.fax || '');
        $('[name="phone_02"]').val(data.phone_02 || '');
        $('[name="wa_name"]').val(data.wa_name || '');
        $('[name="nik"]').val(data.nik || '');
        $('[name="nik_name"]').val(data.nik_name || '');
        resetKtpPhotoUi();
        var ktpUrl = resolveKtpPhotoUrl(data);
        if (data.ktp_photo_path) {
            $('#ktpPhotoUpload').attr('data-ktp-photo-path', data.ktp_photo_path);
        }
        if (ktpUrl) {
            $('#removeKtpPhoto').val('0');
            syncKtpPhotoPreview(ktpUrl, { showViewLink: true });
        }
        $('[name="passcode"]').val(data.passcode || '');
        $('#selPriority').val(data.priority || 'non_vip');
        $('[name="effective_start"]').val(data.effective_start ? String(data.effective_start).substring(0, 10) : '');
        $('[name="invoice_due_days"]').val(data.invoice_due_days ?? 0);
        $('[name="max_weight_kg"]').val(data.max_weight_kg ?? 0);
        setDecimalFieldValue($('[name="minimum_charge"]'), data.minimum_charge ?? 0);

        // Pricings
        $('#pricingContainer').empty();
        if (data.pricings && data.pricings.length) {
            data.pricings.forEach(function (p) {
                addPricingRow(p);
            });
        }

        var relatedId = data.related_marking_id != null && data.related_marking_id !== ''
            ? String(data.related_marking_id)
            : '';
        var relatedLabel = data.related_marking_label || '';
        $('#formModal')
            .attr('data-related-id', relatedId)
            .attr('data-related-label', relatedLabel);
    }

    function initPricingItemSelect($select, selectedId) {
        if (!$select.length || typeof window.initSelect2 !== 'function') {
            return;
        }

        window.initSelect2($select, {
            dropdownParent: $('#formModal'),
            allowClear: true,
        });

        if (selectedId) {
            $select.val(String(selectedId)).trigger('change.select2');
        }
    }

    function prefillPricingRowFromMasterIfEmpty($row) {
        if (!$row || !$row.length) return;
        var itemId = $row.find('[name="p_pricing_item_id"]').val();
        var $vol = $row.find('[name="p_volume_price"]');
        var $gw = $row.find('[name="p_gw_price"]');
        if (getNumericFromInput($vol) > 0 || getNumericFromInput($gw) > 0) {
            return;
        }
        var prices = masterPricesForItem(itemId);
        setCurrencyFieldValue($vol, prices.volume_price);
        setCurrencyFieldValue($gw, prices.gw_price);
    }

    function addPricingRow(data) {
        var tpl = document.getElementById('pricingRowTemplate');
        var clone = tpl.content.cloneNode(true);
        $('#pricingContainer').append(clone);

        var $row = $('#pricingContainer .pricing-row').last();
        var $itemSelect = $row.find('[name="p_pricing_item_id"]');
        var $itemName = $row.find('[name="p_item_name"]');
        var $vol = $row.find('[name="p_volume_price"]');
        var $gw = $row.find('[name="p_gw_price"]');

        initPricingItemSelect($itemSelect, data ? data.pricing_item_id : null);
        initMarkingCurrencyMask($row);

        if (data) {
            if ($itemName.length) {
                $itemName.val(data.item_name || '');
            }
            setCurrencyFieldValue($vol, data.volume_price);
            setCurrencyFieldValue($gw, data.gw_price);
        }
    }

    function collectPricings() {
        var pricings = [];
        $('#pricingContainer .pricing-row').each(function () {
            var $r = $(this);
            var itemId = $r.find('[name="p_pricing_item_id"]').val();
            if (!itemId) return;
            var row = {
                pricing_item_id: itemId,
                item_name: String($r.find('[name="p_item_name"]').val() || '').trim(),
                volume_price: getNumericFromInput($r.find('[name="p_volume_price"]')),
                gw_price: getNumericFromInput($r.find('[name="p_gw_price"]'))
            };
            pricings.push(row);
        });
        return pricings;
    }

    function buildMarkingFormData() {
        var fd = new FormData();
        $('#dataForm').serializeArray().forEach(function (f) {
            if (f.name.startsWith('p_') || f.name === 'minimum_charge' || f.name === 'invoice_due_days' || f.name === 'max_weight_kg' || f.name === 'related_marking_id') {
                return;
            }
            fd.append(f.name, f.value);
        });
        fd.set('related_marking_id', relatedMarkingIdForSave());
        var invoiceDueRaw = String($('[name="invoice_due_days"]').val() || '').trim();
        var invoiceDue = parseInt(invoiceDueRaw, 10);
        fd.set('invoice_due_days', String(Number.isFinite(invoiceDue) && invoiceDue >= 0 ? invoiceDue : 0));
        fd.set('max_weight_kg', String(parseDecimalField($('[name="max_weight_kg"]'))));
        fd.set('minimum_charge', String(parseDecimalField($('[name="minimum_charge"]'))));
        var fileInput = document.getElementById('inputKtpPhoto');
        if (fileInput && fileInput.files && fileInput.files[0]) {
            fd.append('ktp_photo', fileInput.files[0]);
        }
        var pricings = collectPricings();
        pricings.forEach(function (p, i) {
            fd.append('pricings[' + i + '][pricing_item_id]', p.pricing_item_id);
            fd.append('pricings[' + i + '][item_name]', p.item_name || '');
            fd.append('pricings[' + i + '][volume_price]', String(p.volume_price));
            fd.append('pricings[' + i + '][gw_price]', String(p.gw_price));
        });
        fd.set('_token', token);
        return fd;
    }

    function simpanData() {
        var btn = document.getElementById('btnSave'), spinner = document.getElementById('btnSpinner');
        btn.disabled = true; spinner.classList.remove('d-none');
        $('.is-invalid').removeClass('is-invalid');

        var runSave = function () {
            var url = '/marking';
            var fd = buildMarkingFormData();

            if (mode == 2) {
                url = '/marking/' + $('#formModal').attr('data-edit-id');
                fd.append('_method', 'PUT');
            }

            $.ajax({
            url: url,
            data: fd,
            method: 'post',
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
            success: function (e) {
                var saved = e.data || {};
                var savedRelatedId = saved.related_marking_id != null && saved.related_marking_id !== ''
                    ? String(saved.related_marking_id)
                    : '';
                if (mode === 2 && saved.id) {
                    $('#formModal').attr('data-edit-id', saved.id);
                }
                $('#formModal')
                    .attr('data-related-id', savedRelatedId)
                    .attr('data-related-label', savedRelatedId ? (saved.related_marking_label || '') : '');

                patchMarkingRelatedInTable(saved);

                bootstrap.Modal.getInstance(document.getElementById('formModal')).hide();
                notifikasi('success', mode == 1 ? 'Berhasil Tambah' : 'Berhasil Update', e.message);
                refreshData();
            },
            error: function (xhr) {
                var json = xhr.responseJSON;
                if (json && json.errors) {
                    Object.keys(json.errors).forEach(function (f) {
                        $('#dataForm [name="' + f + '"]').addClass('is-invalid');
                    });
                    notifikasi('error', 'Gagal', Object.values(json.errors).flat().join('\n'));
                } else {
                    notifikasi('error', 'Gagal', json ? json.message : 'Gagal menyimpan.');
                }
            },
            complete: function () { btn.disabled = false; spinner.classList.add('d-none'); }
            });
        };

        runSave();
    }

    function hapusData(id) {
        $.ajax({
            url: '/marking/' + id, data: { _token: token }, method: 'delete',
            success: function (e) { notifikasi('success', 'Berhasil Delete', e.message); refreshData(); },
            error: function (xhr) { notifikasi('error', 'Gagal', xhr.responseJSON ? xhr.responseJSON.message : 'Gagal menghapus.'); }
        });
    }
});
