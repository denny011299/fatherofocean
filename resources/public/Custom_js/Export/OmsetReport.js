(function ($) {
    'use strict';

    var $form = $('#filterForm');
    var $results = $('#omsetResults');
    var $btn = $('#btnShowOmset');
    var loading = false;
    var datePresetUi = null;
    var initialResultsHtml = '';

    function filterParams() {
        var params = {};

        if (window.DatePresetFilter) {
            Object.assign(
                params,
                window.DatePresetFilter.getApiParams(
                    '#filterDatePreset',
                    '#filterTanggalFrom',
                    '#filterTanggalTo',
                    'date_preset'
                )
            );
        }

        var partai = ($('#filterPartai').val() || '').trim();
        if (partai) {
            params.partai = partai;
        }

        return params;
    }

    function syncUrl(params) {
        var qs = new URLSearchParams(params);
        var url = (window.omsetPageUrl || window.location.pathname) + '?' + qs.toString();
        window.history.replaceState(null, '', url);
    }

    function setLoading(isLoading) {
        loading = isLoading;
        $btn.prop('disabled', isLoading);
        if (isLoading) {
            $btn.data('orig-html', $btn.html());
            $btn.html('<span class="spinner-border spinner-border-sm me-1"></span>Memuat...');
            $results.css('opacity', '0.55');
        } else {
            if ($btn.data('orig-html')) {
                $btn.html($btn.data('orig-html'));
            }
            $results.css('opacity', '');
        }
    }

    function loadOmset() {
        if (loading) {
            return;
        }
        var params = filterParams();
        syncUrl(params);
        setLoading(true);

        $.getJSON(window.omsetDataUrl, params)
            .done(function (res) {
                $results.html(res.html || '');
            })
            .fail(function (xhr) {
                var msg = 'Gagal memuat data omset.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $results.html(
                    '<div class="glass p-5 text-center">'
                    + '<i class="bi bi-exclamation-triangle text-danger" style="font-size:2rem"></i>'
                    + '<p class="mt-3 mb-0" style="color:var(--text-muted)">' + msg + '</p>'
                    + '</div>'
                );
            })
            .always(function () {
                setLoading(false);
            });
    }

    function resetFilters() {
        $('#filterDatePreset').val('');
        $('#filterTanggalFrom').val('');
        $('#filterTanggalTo').val('');
        $('#filterPartai').val('').trigger('change');
        if (datePresetUi && typeof datePresetUi.reset === 'function') {
            datePresetUi.reset();
        }
        $('#filterDateCustomRow').addClass('d-none');
        $results.html(initialResultsHtml);
        syncUrl({});
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        loadOmset();
    });

    $('#btnResetOmsetFilter').on('click', resetFilters);

    $(function () {
        initialResultsHtml = $results.html();

        if ($.fn.select2) {
            $('#filterPartai').select2({
                width: '100%',
                allowClear: true,
                placeholder: $('#filterPartai').data('placeholder') || '',
            });
        }

        if (window.DatePresetFilter) {
            datePresetUi = window.DatePresetFilter.init({
                presetSelector: '#filterDatePreset',
                customRowSelector: '#filterDateCustomRow',
                fromSelector: '#filterTanggalFrom',
                toSelector: '#filterTanggalTo',
            });
        }

        if (window.omsetAutoLoad) {
            loadOmset();
        }
    });
}(jQuery));
