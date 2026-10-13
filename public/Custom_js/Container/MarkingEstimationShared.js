/**
 * Shared helpers: estimasi muat via marking (create + load pages).
 */
(function (global) {
    'use strict';

    var PRESET_SELECT = '#estFilterDatePreset';
    var CUSTOM_ROW = '#estFilterDateCustomRow';
    var FROM_SELECT = '#estFilterTanggalFrom';
    var TO_SELECT = '#estFilterTanggalTo';
    var PAGE_SIZE = 8;

    var listState = {
        items: [],
        page: 1,
        checkedIds: {},
        isStaged: function () {
            return false;
        },
    };

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function t(key, replacements) {
        var s = (global.__t && global.__t[key]) || key;
        if (replacements) {
            Object.keys(replacements).forEach(function (k) {
                s = String(s).replace(':' + k, replacements[k]);
            });
        }
        return s;
    }

    function getDateFilterParams() {
        if (!global.DatePresetFilter) {
            return {};
        }
        return global.DatePresetFilter.getApiParams(
            PRESET_SELECT,
            FROM_SELECT,
            TO_SELECT,
            'date_preset',
        );
    }

    function hasDateFilter() {
        return Object.keys(getDateFilterParams()).length > 0;
    }

    function canLoadPreview(markingId) {
        return Boolean(markingId) || hasDateFilter();
    }

    function initDateFilter(onChange) {
        if (!global.DatePresetFilter) {
            return null;
        }
        return global.DatePresetFilter.init({
            presetSelector: PRESET_SELECT,
            customRowSelector: CUSTOM_ROW,
            fromSelector: FROM_SELECT,
            toSelector: TO_SELECT,
            onChange: onChange,
        });
    }

    function isChecked(id) {
        return !!listState.checkedIds[id];
    }

    function setChecked(id, checked) {
        if (checked) {
            listState.checkedIds[id] = true;
        } else {
            delete listState.checkedIds[id];
        }
    }

    function getSelectableItems() {
        return listState.items.filter(function (item) {
            return !listState.isStaged(item.id);
        });
    }

    function initCheckedIds(items, isStaged) {
        listState.checkedIds = {};
        items.forEach(function (item) {
            if (!isStaged(item.id)) {
                listState.checkedIds[item.id] = true;
            }
        });
    }

    function buildRowHtml(item) {
        var disabled = listState.isStaged(item.id);
        var metaParts = [];
        if (item.marking) {
            metaParts.push(item.marking);
        }
        if (item.tanggal_masuk_display) {
            metaParts.push(item.tanggal_masuk_display);
        }
        if (item.packing_name) {
            metaParts.push(item.packing_name);
        }
        var summary = item.barang_summary && item.barang_summary !== '-' ? item.barang_summary : '';
        var checked = !disabled && isChecked(item.id);
        var volLine = esc(t('coly_cbm_unit', {
            coly: item.ctns,
            cbm: window.fmtCbm ? window.fmtCbm(item.total_cbm || 0) : Number(item.total_cbm || 0).toFixed(3),
        }));

        var html = '<tr class="marking-preview-row' + (disabled ? ' is-disabled' : '') + '">'
            + '<td class="marking-preview-td-check text-center">'
            + '<input type="checkbox" class="form-check-input preview-barang-check m-0" value="' + item.id + '"'
            + (disabled ? ' disabled' : '') + (checked ? ' checked' : '') + '>'
            + '</td>'
            + '<td class="marking-preview-td-detail">'
            + '<div class="marking-preview-index fw-600">' + esc(item.no_index) + '</div>';
        if (metaParts.length) {
            html += '<div class="marking-preview-meta text-muted-glass">' + esc(metaParts.join(' · ')) + '</div>';
        }
        if (summary) {
            html += '<div class="marking-preview-type text-muted-glass" title="' + esc(summary) + '">'
                + '<span class="marking-preview-type-label">' + esc(t('preview_col_item')) + ':</span> '
                + esc(summary) + '</div>';
        }
        html += '</td>'
            + '<td class="marking-preview-td-vol text-end text-nowrap">'
            + '<div class="marking-preview-vol-cbm text-accent fw-600">' + volLine + '</div>'
            + '</td></tr>';
        return html;
    }

    function renderCurrentPage($el) {
        var total = listState.items.length;
        var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        var page = Math.min(Math.max(1, listState.page), totalPages);
        listState.page = page;
        var start = (page - 1) * PAGE_SIZE;
        var pageItems = listState.items.slice(start, start + PAGE_SIZE);

        var html = '<div class="marking-preview-panel glass-solid">'
            + '<div class="marking-preview-toolbar">'
            + '<span class="marking-preview-title fw-600">' + esc(t('preview_items_in_marking')) + '</span>'
            + '<span class="badge-glass marking-preview-count">' + esc(t('items_count', { count: String(total) })) + '</span>'
            + '</div>'
            + '<div class="marking-preview-scroll table-responsive">'
            + '<table class="table table-glass table-sm marking-preview-table mb-0">'
            + '<thead><tr>'
            + '<th class="marking-preview-th-check text-center" scope="col">'
            + '<input type="checkbox" class="form-check-input m-0" id="previewBarangCheckAll" title="' + esc(t('preview_check_all')) + '">'
            + '</th>'
            + '<th class="marking-preview-th-detail" scope="col">' + esc(t('col_no_index')) + '</th>'
            + '<th class="marking-preview-th-vol text-end" scope="col">' + esc(t('col_coly')) + ' · CBM</th>'
            + '</tr></thead><tbody>';

        pageItems.forEach(function (item) {
            html += buildRowHtml(item);
        });

        html += '</tbody></table></div>';

        if (totalPages > 1) {
            html += '<div class="marking-preview-pager">'
                + '<button type="button" class="btn btn-glass btn-sm marking-preview-pager-btn" data-dir="prev"'
                + (page <= 1 ? ' disabled' : '') + '>'
                + '<i class="bi bi-chevron-left"></i> ' + esc(t('preview_pager_prev')) + '</button>'
                + '<span class="small text-muted marking-preview-pager-info">'
                + esc(t('preview_pager_page', { page: String(page), total: String(totalPages) }))
                + ' · ' + esc(t('preview_pager_count', {
                    shown: String(pageItems.length),
                    total: String(total),
                }))
                + '</span>'
                + '<button type="button" class="btn btn-glass btn-sm marking-preview-pager-btn" data-dir="next"'
                + (page >= totalPages ? ' disabled' : '') + '>'
                + esc(t('preview_pager_next')) + ' <i class="bi bi-chevron-right"></i></button>'
                + '</div>';
        }

        html += '</div>';
        $el.html(html);
        syncPreviewCheckAllState();
    }

    /**
     * @param {jQuery|HTMLElement} $container
     * @param {Array} items
     * @param {{ isStaged: function(number): boolean }} opts
     */
    function renderPreviewBarangList($container, items, opts) {
        var $el = $container instanceof jQuery ? $container : $($container);
        if (!items || !items.length) {
            $el.empty();
            listState.items = [];
            return;
        }

        listState.isStaged = opts && typeof opts.isStaged === 'function' ? opts.isStaged : function () {
            return false;
        };
        listState.items = items;
        listState.page = 1;
        initCheckedIds(items, listState.isStaged);
        renderCurrentPage($el);
    }

    function getSelectedItems() {
        return listState.items.filter(function (item) {
            return isChecked(item.id) && !listState.isStaged(item.id);
        });
    }

    function selectablePreviewChecks() {
        return $('#previewBarangList .preview-barang-check:not(:disabled)');
    }

    function syncPreviewCheckAllState() {
        var $master = $('#previewBarangCheckAll');
        if (!$master.length) {
            return;
        }
        var selectable = getSelectableItems();
        if (!selectable.length) {
            $master.prop({ checked: false, indeterminate: false, disabled: true });
            return;
        }
        $master.prop('disabled', false);
        var checkedCount = selectable.filter(function (item) {
            return isChecked(item.id);
        }).length;
        $master.prop('checked', checkedCount === selectable.length);
        $master.prop('indeterminate', checkedCount > 0 && checkedCount < selectable.length);
    }

    /**
     * @param {function(): void} onItemChange
     */
    function bindPreviewCheckAllHandlers(onItemChange) {
        $(document).off('.markingPreviewCheck');

        $(document).on('change.markingPreviewCheck', '#previewBarangCheckAll', function () {
            var checked = $(this).prop('checked');
            getSelectableItems().forEach(function (item) {
                setChecked(item.id, checked);
            });
            $(this).prop('indeterminate', false);
            var $wrap = $('#previewBarangList');
            if ($wrap.length && listState.items.length) {
                renderCurrentPage($wrap);
            }
            if (typeof onItemChange === 'function') {
                onItemChange();
            }
        });

        $(document).on('change.markingPreviewCheck', '.preview-barang-check', function () {
            var id = parseInt($(this).val(), 10);
            setChecked(id, $(this).prop('checked'));
            syncPreviewCheckAllState();
            if (typeof onItemChange === 'function') {
                onItemChange();
            }
        });

        $(document).on('click.markingPreviewCheck', 'tr.marking-preview-row', function (e) {
            if ($(e.target).closest('input, a, button, label').length) {
                return;
            }
            var $cb = $(this).find('.preview-barang-check').first();
            if ($cb.length && !$cb.prop('disabled')) {
                $cb.prop('checked', !$cb.prop('checked')).trigger('change');
            }
        });

        $(document).on('click.markingPreviewCheck', '.marking-preview-pager-btn', function () {
            if ($(this).prop('disabled')) {
                return;
            }
            var dir = $(this).data('dir');
            var totalPages = Math.max(1, Math.ceil(listState.items.length / PAGE_SIZE));
            if (dir === 'prev' && listState.page > 1) {
                listState.page -= 1;
            } else if (dir === 'next' && listState.page < totalPages) {
                listState.page += 1;
            }
            var $wrap = $('#previewBarangList');
            if ($wrap.length) {
                renderCurrentPage($wrap);
            }
        });
    }

    function applyPreviewHeader(res) {
        if (res.marking) {
            $('#previewMarkingCode').text(res.marking.code);
            $('#previewCustomer').text(res.marking.customer_name || res.marking.description || '-');
        } else {
            $('#previewMarkingCode').text(t('preview_all_markings'));
            $('#previewCustomer').text(
                (res.barangs && res.barangs.length)
                    ? res.barangs.length + ' ' + t('items_count_suffix')
                    : '-',
            );
        }
        $('#previewCtns').text(res.totals.ctns);
        $('#previewCbm').text(window.fmtCbm ? window.fmtCbm(res.totals.total_cbm) : Number(res.totals.total_cbm).toFixed(3));
        $('#previewGw').text(Number(res.totals.total_gw).toFixed(2));
    }

    global.MarkingEstimationPreview = {
        esc: esc,
        t: t,
        getDateFilterParams: getDateFilterParams,
        hasDateFilter: hasDateFilter,
        canLoadPreview: canLoadPreview,
        initDateFilter: initDateFilter,
        renderPreviewBarangList: renderPreviewBarangList,
        getSelectedItems: getSelectedItems,
        syncPreviewCheckAllState: syncPreviewCheckAllState,
        bindPreviewCheckAllHandlers: bindPreviewCheckAllHandlers,
        applyPreviewHeader: applyPreviewHeader,
    };
})(window);
