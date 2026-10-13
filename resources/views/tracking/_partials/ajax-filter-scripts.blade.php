@push('styles')
<style>
.tracking-ajax-loading {
    opacity: 0.45;
    pointer-events: none;
    transition: opacity 0.15s ease;
}
.tracking-ajax-loading::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(11, 15, 26, 0.12);
    border-radius: inherit;
    pointer-events: none;
}
.tracking-table-wrap {
    position: relative;
}
.tracking-table-wrap th.col-mark-customer,
.tracking-table-wrap td.col-mark-customer {
    min-width: 160px;
    max-width: 280px;
    vertical-align: top;
}
.tracking-table-wrap .mark-cell {
    max-width: none;
}
.tracking-table-wrap .mark-cell .mark-code,
.tracking-table-wrap .mark-cell .mark-meta {
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    line-height: 1.35;
}
.tracking-col-notes {
    min-width: 140px;
    max-width: 260px;
    vertical-align: top;
}
.tracking-notes-text {
    display: block;
    white-space: normal;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    line-height: 1.45;
    color: var(--text-muted);
    font-size: 0.875rem;
}
.tracking-col-actions {
    vertical-align: top;
    width: 1%;
    white-space: nowrap;
}
.tracking-col-actions .tracking-action-group {
    flex-wrap: nowrap;
    justify-content: flex-end;
}
</style>
@endpush

@push('scripts')
<script>
(function () {
    var pendingConfigs = [];

    window.registerTrackingAjaxFilter = function (config) {
        if (typeof window.initTrackingAjaxFilter === 'function') {
            window.initTrackingAjaxFilter(config);
            return;
        }
        pendingConfigs.push(config);
    };

    function flushPendingConfigs() {
        if (typeof window.initTrackingAjaxFilter !== 'function') {
            return;
        }
        pendingConfigs.forEach(function (config) {
            window.initTrackingAjaxFilter(config);
        });
        pendingConfigs = [];
    }

    function initModule($) {
        var DEBOUNCE_MS = 350;
        var pendingXhr = null;

        function buildQuery($form, page) {
            var params = $form.serializeArray().filter(function (item) {
                return item.value !== '';
            });
            if (page) {
                params.push({ name: 'page', value: page });
            }
            params.push({ name: 'ajax', value: '1' });
            return $.param(params);
        }

        function syncUrl($form, page) {
            var params = $form.serializeArray().filter(function (item) {
                return item.value !== '';
            });
            if (page) {
                params.push({ name: 'page', value: page });
            }
            var query = $.param(params);
            history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
        }

        function resolveTableWrap($form, options) {
            if (options.tableWrapperSelector) {
                return $(options.tableWrapperSelector);
            }
            var $body = $(options.tableBodySelector);
            return $body.closest('.table-responsive, .tracking-table-wrap');
        }

        window.initTrackingAjaxFilter = function (options) {
            var $form = $(options.formSelector);
            if (!$form.length) {
                return;
            }

            var $tableWrap = resolveTableWrap($form, options);

            function setLoading(isLoading) {
                if (!$tableWrap.length) {
                    return;
                }
                $tableWrap.toggleClass('tracking-ajax-loading', isLoading);
                $(options.tableBodySelector).attr('aria-busy', isLoading ? 'true' : 'false');
            }

            function fetchResults(page) {
                if (pendingXhr) {
                    pendingXhr.abort();
                }

                setLoading(true);

                pendingXhr = $.ajax({
                    url: options.url || window.location.pathname,
                    method: 'GET',
                    data: buildQuery($form, page),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    success: function (res) {
                        if (options.statsSelector && res.stats_html) {
                            $(options.statsSelector).replaceWith(res.stats_html);
                        }
                        $(options.tableBodySelector).html(res.table_html || '');
                        $(options.paginationSelector).html(res.pagination_html || '');
                        if (options.countSelector) {
                            $(options.countSelector).text(res.total_label || String(res.total));
                        }
                        syncUrl($form, page);
                        if (typeof options.onUpdated === 'function') {
                            options.onUpdated();
                        }
                    },
                    error: function (xhr) {
                        if (xhr.statusText === 'abort') {
                            return;
                        }
                        if (typeof showToast === 'function') {
                            showToast(xhr.responseJSON?.message || 'Gagal memuat data', 'error');
                        }
                    },
                    complete: function () {
                        setLoading(false);
                        pendingXhr = null;
                    },
                });
            }

            var timer;
            $form.on('submit', function (e) {
                e.preventDefault();
                fetchResults();
            });

            $form.find('input[name="search"], input[name="no_resi"]').on('keyup input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetchResults();
                }, DEBOUNCE_MS);
            });

            $form.find('input[type="date"]').on('change', function () {
                fetchResults();
            });

            $form.find('.select2').on('change.select2', function () {
                fetchResults();
            });

            $form.find('select:not(.select2)').on('change', function () {
                fetchResults();
            });

            $(document).on('click', options.paginationSelector + ' .pagination a', function (e) {
                e.preventDefault();
                var href = $(this).attr('href');
                if (!href) {
                    return;
                }
                var page = new URL(href, window.location.origin).searchParams.get('page');
                fetchResults(page);
            });

            if (options.resetSelector) {
                $(options.resetSelector).on('click', function (e) {
                    e.preventDefault();
                    $form[0].reset();
                    $form.find('.select2').val('').trigger('change.select2');
                    clearTimeout(timer);
                    fetchResults();
                });
            }

            if (options.searchButtonSelector) {
                $(options.searchButtonSelector).on('click', function (e) {
                    e.preventDefault();
                    clearTimeout(timer);
                    fetchResults();
                });
            }
        };

        window.openPublicTrackUrl = function (url) {
            if (!url) {
                return;
            }
            window.open(url, '_blank', 'noopener,noreferrer');
        };

        $(document).on('click', '.staff-track-row, .customer-track-row', function (e) {
            if ($(e.target).closest('.staff-track-open, .customer-track-open, .customer-track-download').length) {
                return;
            }
            window.openPublicTrackUrl($(this).data('track-url'));
        });

        $(document).on('keydown', '.staff-track-row, .customer-track-row', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                window.openPublicTrackUrl($(this).data('track-url'));
            }
        });

        flushPendingConfigs();
    }

    function bootModule() {
        if (typeof window.jQuery === 'undefined') {
            return false;
        }
        initModule(window.jQuery);
        return true;
    }

    function scheduleBoot() {
        if (bootModule()) {
            return;
        }
        window.addEventListener('load', function () {
            bootModule();
        }, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBoot);
    } else {
        scheduleBoot();
    }
})();
</script>
@endpush
