(function () {
    'use strict';

    const t = (k, fb) => (window.__t && window.__t[k]) || fb;
    const fmtRp = (n) => 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const EXCLUDE_STORAGE_PREFIX = 'companyRevenueExcludedMarkings:';

    let companyTable = null;
    let excludeModal = null;
    let excludedMarkings = [];

    function excludeStorageKeyFor(marketingId) {
        return marketingId ? EXCLUDE_STORAGE_PREFIX + marketingId : null;
    }

    function excludeStorageKey() {
        return excludeStorageKeyFor(currentMarketingId());
    }

    function formatPeriod(period) {
        if (!period || period.indexOf('-') === -1) return period;
        const parts = period.split('-');
        const m = parseInt(parts[1], 10) - 1;
        return (months[m] || parts[1]) + ' ' + parts[0];
    }

    function currentPeriod() {
        return $('#filterCompanyPeriod').val() || window._payoutDefaultPeriod || '';
    }

    function currentMarketingId() {
        const val = $('#filterCompanyMarketing').val();
        return val ? parseInt(val, 10) : null;
    }

    function modalMarketingId() {
        const val = $('#excludeModalMarketing').val();
        return val ? parseInt(val, 10) : null;
    }

    function periodToDates(period) {
        const parts = period.split('-').map(Number);
        const last = new Date(parts[0], parts[1], 0).getDate();
        const pad = (n) => String(n).padStart(2, '0');
        return {
            date_from: period + '-01',
            date_to: period + '-' + pad(last),
        };
    }

    function updatePeriodLabel() {
        $('#companyTablePeriodLabel').text('— ' + formatPeriod(currentPeriod()));
    }

    function getExcludedFromStorage(marketingId) {
        const key = excludeStorageKeyFor(marketingId);
        if (!key) {
            return [];
        }
        try {
            const raw = sessionStorage.getItem(key);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function loadExcludedFromStorage() {
        excludedMarkings = getExcludedFromStorage(currentMarketingId());
        updateExcludeBadge();
    }

    function saveExcludedToStorage() {
        const key = excludeStorageKey();
        if (!key) {
            excludedMarkings = [];
            updateExcludeBadge();
            return;
        }
        sessionStorage.setItem(key, JSON.stringify(excludedMarkings));
        updateExcludeBadge();
    }

    function updateExcludeBadge() {
        const count = excludedMarkings.length;
        const badge = $('#excludeMarkingCount');
        if (count > 0) {
            badge.text(count).removeClass('d-none');
        } else {
            badge.addClass('d-none');
        }
    }

    function filterParams() {
        const dates = periodToDates(currentPeriod());
        const params = {
            date_from: dates.date_from,
            date_to: dates.date_to,
        };
        const marketingId = currentMarketingId();
        if (marketingId) {
            params.marketing_id = marketingId;
        }
        const partai = $('#filterCompanyPartai').val();
        if (partai) {
            params.partai = partai;
        }
        if (excludedMarkings.length > 0) {
            params.excluded_markings = excludedMarkings;
        }
        return params;
    }

    function renderCategoryTotals(categories) {
        const $row = $('#companyCategoryTotalsRow');
        const $cards = $('#companyCategoryTotalsCards');
        $cards.empty();

        if (!Array.isArray(categories) || categories.length === 0) {
            $row.addClass('d-none');
            return;
        }

        categories.forEach(function (item) {
            const total = Number(item.total || 0);
            if (total <= 0) {
                return;
            }

            const estimasi = Number(item.estimasi || 0);
            const fix = Number(item.fix || 0);
            const count = Number(item.shipment_count || 0);

            $cards.append(
                '<div class="col-md-6 col-xl-4">'
                + '<div class="glass-solid p-3 h-100">'
                + '<div class="fw-700 mb-1" style="color:var(--text-primary)">' + (item.kategori || '-') + '</div>'
                + '<div class="small mb-2" style="color:var(--text-muted)">'
                + count + ' ' + t('category_shipment_count', 'pengiriman')
                + '</div>'
                + '<div class="d-flex flex-wrap gap-3 small">'
                + '<span><span style="color:var(--text-muted)">' + t('status_estimasi', 'Estimasi') + ':</span> '
                + '<strong class="text-warning">' + fmtRp(estimasi) + '</strong></span>'
                + '<span><span style="color:var(--text-muted)">' + t('status_fixed', 'Net Fix') + ':</span> '
                + '<strong class="text-accent">' + fmtRp(fix) + '</strong></span>'
                + '<span><span style="color:var(--text-muted)">Total:</span> '
                + '<strong>' + fmtRp(total) + '</strong></span>'
                + '</div></div></div>'
            );
        });

        if ($cards.children().length > 0) {
            $row.removeClass('d-none');
        } else {
            $row.addClass('d-none');
        }
    }

    function refreshCompanySummary() {
        const params = { period: currentPeriod() };
        const marketingId = currentMarketingId();
        if (marketingId) {
            params.marketing_id = marketingId;
        }
        const partai = $('#filterCompanyPartai').val();
        if (partai) {
            params.partai = partai;
        }
        if (excludedMarkings.length > 0) {
            params.excluded_markings = excludedMarkings;
        }
        $.get('/finance/company-revenue/summary', params, function (totals) {
            $('#statEstimasiCompany').text(fmtRp(totals.estimasi_company));
            $('#statFixCompany').text(fmtRp(totals.fix_company));
            renderCategoryTotals(totals.category_totals || []);
        });
    }

    function loadCompanyData() {
        $.get('/finance/earnings/company-data', filterParams(), function (rows) {
            companyTable.clear().rows.add(rows).draw();
            updatePeriodLabel();
        });
    }

    function reloadAll() {
        refreshCompanySummary();
        loadCompanyData();
    }

    function loadMarkingOptionsForExclude(marketingId, selectedCodes) {
        const $select = $('#excludeMarkingSelect');
        const selected = selectedCodes || [];
        $select.empty();

        if (!marketingId) {
            $select.trigger('change');
            return $.Deferred().resolve().promise();
        }

        return $.get('/finance/company-revenue/marking-options', { sales_id: marketingId }, function (options) {
            options.forEach(function (opt) {
                $select.append(new Option(opt.label, opt.code, false, selected.includes(opt.code)));
            });
            $select.trigger('change');
        });
    }

    function syncExcludeModalForMarketing(marketingId) {
        const selectedCodes = getExcludedFromStorage(marketingId);
        return loadMarkingOptionsForExclude(marketingId, selectedCodes);
    }

    function openExcludeModal() {
        const filterMarketing = currentMarketingId();
        const $modalMarketing = $('#excludeModalMarketing');
        $modalMarketing.val(filterMarketing ? String(filterMarketing) : '');
        if ($.fn.select2) {
            $modalMarketing.trigger('change.select2');
        }
        syncExcludeModalForMarketing(modalMarketingId());
        excludeModal.show();
    }

    $(function () {
        if (!$('#companyRevenueTable').length) return;

        loadExcludedFromStorage();

        if ($.fn.select2) {
            $('#filterCompanyMarketing').select2({ width: '100%', allowClear: true });
            $('#filterCompanyPartai').select2({
                width: '100%',
                allowClear: true,
                placeholder: t('filter_all_partai', 'Semua Partai'),
            });
            $('#excludeModalMarketing').select2({
                width: '100%',
                allowClear: true,
                dropdownParent: $('#modalExcludeMarking'),
                placeholder: t('exclude_pick_marketing', 'Pilih marketing'),
            });
            $('#excludeMarkingSelect').select2({
                width: '100%',
                dropdownParent: $('#modalExcludeMarking'),
                placeholder: t('exclude_marking_placeholder', 'Pilih marking yang dikecualikan'),
            });
        }

        excludeModal = new bootstrap.Modal(document.getElementById('modalExcludeMarking'));

        companyTable = $('#companyRevenueTable').DataTable({
            data: [],
            columns: [
                { data: 'no_index', defaultContent: '-' },
                { data: 'tanggal_masuk', defaultContent: '-' },
                { data: 'marking', defaultContent: '-' },
                { data: 'kategori', defaultContent: '-' },
                {
                    data: 'partai',
                    defaultContent: '-',
                    render: (d) => d || t('partai_not_loaded', 'Belum dimuat'),
                },
                { data: 'billable_base', render: (d) => fmtRp(d) },
                { data: 'company_profit', render: (d) => fmtRp(d) },
                {
                    data: null,
                    render: (_, __, row) => (!row.is_fixed ? fmtRp(row.company_profit) : '-'),
                },
                {
                    data: null,
                    render: (_, __, row) => (row.is_fixed ? fmtRp(row.company_profit) : '-'),
                },
            ],
            order: [[0, 'desc']],
            pageLength: 15,
            lengthMenu: [[15, 25, 50, -1], [15, 25, 50, 'Semua']],
            language: window.dataTablesIdLang || {},
        });

        reloadAll();

        $('#btnRefreshCompany').on('click', reloadAll);
        $('#filterCompanyPeriod, #filterCompanyPartai').on('change', reloadAll);
        $('#filterCompanyMarketing').on('change', function () {
            loadExcludedFromStorage();
            reloadAll();
        });

        $('#excludeModalMarketing').on('change', function () {
            syncExcludeModalForMarketing(modalMarketingId());
        });

        $('#btnCompanyExclude').on('click', openExcludeModal);

        $('#btnSaveExcludeMarking').on('click', function () {
            const marketingId = modalMarketingId();
            if (!marketingId) {
                showToast(t('exclude_pick_marketing', 'Pilih marketing'), 'warning');
                return;
            }

            excludedMarkings = $('#excludeMarkingSelect').val() || [];
            const $filterMarketing = $('#filterCompanyMarketing');
            $filterMarketing.val(String(marketingId));
            if ($.fn.select2) {
                $filterMarketing.trigger('change.select2');
            }
            saveExcludedToStorage();
            excludeModal.hide();
            reloadAll();
        });
    });
})();
