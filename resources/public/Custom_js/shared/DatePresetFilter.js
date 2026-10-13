/**
 * Shared date-preset filter (none / today / yesterday / 7d / 30d / custom).
 * Expects window.__t keys: date_preset_none, date_preset_today, etc.
 */
(function (global) {
    'use strict';

    var PRESETS = ['', 'today', 'yesterday', '7d', '30d', 'this_month', 'this_year', 'custom'];

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function localTodayYmd() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function addDaysYmd(ymd, days) {
        var parts = String(ymd).slice(0, 10).split('-').map(Number);
        var d = new Date(parts[0], parts[1] - 1, parts[2]);
        d.setDate(d.getDate() + days);
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function normalizeYmd(str) {
        var raw = String(str || '').trim().slice(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return '';
        }
        var parts = raw.split('-').map(Number);
        var year = parts[0];
        var month = parts[1];
        var day = parts[2];
        if (month < 1 || month > 12 || day < 1 || day > 31) {
            return '';
        }
        var check = new Date(year, month - 1, day);
        if (
            check.getFullYear() !== year
            || check.getMonth() !== month - 1
            || check.getDate() !== day
        ) {
            return '';
        }
        return raw;
    }

    function ymdToDdMmYyyy(ymd) {
        if (!ymd) return '';
        var p = String(ymd).slice(0, 10).split('-');
        if (p.length !== 3) return '';
        return pad2(p[2]) + '/' + pad2(p[1]) + '/' + p[0];
    }

    function ddMmYyyyToYmd(str) {
        var raw = String(str || '').trim();
        if (!raw) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw.slice(0, 10))) {
            return normalizeYmd(raw);
        }
        var m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) return '';
        var day = parseInt(m[1], 10);
        var month = parseInt(m[2], 10);
        var year = parseInt(m[3], 10);
        if (month < 1 || month > 12 || day < 1 || day > 31) return '';
        var iso = year + '-' + pad2(month) + '-' + pad2(day);
        return normalizeYmd(iso);
    }

    function boundsForPreset(preset) {
        var today = localTodayYmd();
        switch (preset) {
            case 'today':
                return { from: today, to: today };
            case 'yesterday':
                var y = addDaysYmd(today, -1);
                return { from: y, to: y };
            case '7d':
                return { from: addDaysYmd(today, -6), to: today };
            case '30d':
                return { from: addDaysYmd(today, -29), to: today };
            case 'this_month': {
                var parts = today.split('-').map(Number);
                var lastDay = new Date(parts[0], parts[1], 0).getDate();
                return {
                    from: parts[0] + '-' + pad2(parts[1]) + '-01',
                    to: parts[0] + '-' + pad2(parts[1]) + '-' + pad2(lastDay),
                };
            }
            case 'this_year':
                return { from: today.slice(0, 4) + '-01-01', to: today.slice(0, 4) + '-12-31' };
            default:
                return null;
        }
    }

    function presetLabel(preset) {
        var t = global.__t || {};
        var keyMap = {
            '': 'date_preset_none',
            today: 'date_preset_today',
            yesterday: 'date_preset_yesterday',
            '7d': 'date_preset_7d',
            '30d': 'date_preset_30d',
            this_month: 'date_preset_this_month',
            this_year: 'date_preset_this_year',
            custom: 'date_preset_custom',
        };
        var key = keyMap[preset] || 'date_preset_none';
        return t[key] || preset || '';
    }

    function toggleCustomRow($row, show) {
        if (!$row || !$row.length) return;
        $row.toggleClass('d-none', !show);
    }

    function applyPresetToInputs(preset, $from, $to) {
        if (preset === 'custom') {
            return;
        }
        if (!preset) {
            if ($from && $from.length) $from.val('');
            if ($to && $to.length) $to.val('');
            return;
        }
        var bounds = boundsForPreset(preset);
        if (!bounds) return;
        if ($from && $from.length) $from.val(bounds.from);
        if ($to && $to.length) $to.val(bounds.to);
    }

    /**
     * @param {object} opts
     * @param {string} opts.presetSelector
     * @param {string} [opts.customRowSelector]
     * @param {string} [opts.fromSelector]
     * @param {string} [opts.toSelector]
     * @param {function} [opts.onChange]
     */
    function init(opts) {
        var $preset = $(opts.presetSelector);
        var $row = opts.customRowSelector ? $(opts.customRowSelector) : $();
        var $from = opts.fromSelector ? $(opts.fromSelector) : $();
        var $to = opts.toSelector ? $(opts.toSelector) : $();

        function syncUi(triggerChange) {
            var preset = $preset.val() || '';
            toggleCustomRow($row, preset === 'custom');
            if (preset !== 'custom') {
                applyPresetToInputs(preset, $from, $to);
            }
            if (triggerChange && typeof opts.onChange === 'function') {
                opts.onChange();
            }
        }

        $preset.on('change', function () {
            syncUi(true);
        });

        $from.add($to).on('change blur', function () {
            if (($preset.val() || '') === 'custom' && typeof opts.onChange === 'function') {
                opts.onChange();
            }
        });

        syncUi(false);

        return {
            reset: function () {
                $preset.val('');
                syncUi(false);
            },
            clearDates: function () {
                $preset.val('');
                syncUi(false);
            },
            getPreset: function () {
                return $preset.val() || '';
            },
            setPreset: function (value) {
                $preset.val(value || '');
                syncUi(false);
            },
        };
    }

    /**
     * API params for list endpoints.
     * @param {string} presetParamName e.g. date_preset or period
     */
    function getApiParams(presetSelector, fromSelector, toSelector, presetParamName) {
        var preset = ($(presetSelector).val() || '').trim();
        var paramName = presetParamName || 'date_preset';

        if (!preset) {
            var emptyParams = {};
            emptyParams[paramName] = '';
            return emptyParams;
        }

        var fromYmd = '';
        var toYmd = '';

        if (preset === 'custom') {
            fromYmd = normalizeYmd($(fromSelector).val());
            toYmd = normalizeYmd($(toSelector).val());
            if (!fromYmd && !toYmd) {
                var customParams = {};
                customParams[paramName] = preset;
                return customParams;
            }
        } else {
            var bounds = boundsForPreset(preset);
            if (!bounds) {
                var fallbackParams = {};
                fallbackParams[paramName] = preset;
                return fallbackParams;
            }
            fromYmd = bounds.from;
            toYmd = bounds.to;
        }

        var params = {};
        params[paramName] = preset;
        if (fromYmd) {
            params.tanggal_from = fromYmd;
        }
        if (toYmd) {
            params.tanggal_to = toYmd;
        }
        return params;
    }

    global.DatePresetFilter = {
        PRESETS: PRESETS,
        localTodayYmd: localTodayYmd,
        normalizeYmd: normalizeYmd,
        ymdToDdMmYyyy: ymdToDdMmYyyy,
        ddMmYyyyToYmd: ddMmYyyyToYmd,
        boundsForPreset: boundsForPreset,
        presetLabel: presetLabel,
        init: init,
        getApiParams: getApiParams,
        applyPresetToInputs: applyPresetToInputs,
        toggleCustomRow: toggleCustomRow,
    };
})(typeof window !== 'undefined' ? window : this);
