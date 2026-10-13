(function () {
    'use strict';

    /**
     * Client-side tab switching for finance-ops-tab UI (Bootstrap Tab is unreliable with this markup).
     *
     * @param {object} config
     * @param {string} config.tabsRootId
     * @param {string} config.contentId
     * @param {Object<string, function|undefined>} [config.onShow]
     * @param {Object<string, string>} [config.urlByPane]
     * @param {string} [config.defaultUrl]
     * @param {string} [config.initialPane] - pane selector from ?tab= query, e.g. '#tabPengajuanEdit'
     */
    window.initFinanceOpsTabs = function (config) {
        var root = document.getElementById(config.tabsRootId);
        var content = document.getElementById(config.contentId);
        if (!root || !content) {
            return;
        }

        var onShow = config.onShow || {};
        var urlByPane = config.urlByPane || {};
        var defaultUrl = config.defaultUrl || window.location.pathname;
        var bound = false;

        function syncTabButtons(activeBtn) {
            root.querySelectorAll('.finance-ops-tab').forEach(function (tab) {
                var on = tab === activeBtn;
                tab.classList.toggle('is-active', on);
                tab.classList.toggle('active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        function showPane(targetSelector) {
            content.querySelectorAll('.tab-pane').forEach(function (pane) {
                pane.classList.remove('show', 'active');
            });
            var pane = content.querySelector(targetSelector);
            if (pane) {
                pane.classList.add('show', 'active');
            }
        }

        function normalizeUrl(url) {
            var anchor = document.createElement('a');
            anchor.href = url;
            return anchor.pathname + anchor.search;
        }

        function updateUrl(targetSelector) {
            var url = urlByPane[targetSelector] || defaultUrl;
            var next = normalizeUrl(url);
            var current = window.location.pathname + window.location.search;
            if (current !== next) {
                history.replaceState(null, '', url);
            }
        }

        function runPaneHandler(targetSelector) {
            var fn = onShow[targetSelector];
            if (typeof fn === 'function') {
                fn();
            }
        }

        function activateTab(btn, skipUrl) {
            if (!btn) {
                return;
            }
            var target = btn.getAttribute('data-bs-target');
            if (!target) {
                return;
            }

            syncTabButtons(btn);
            showPane(target);
            if (!skipUrl) {
                updateUrl(target);
            }
            runPaneHandler(target);
        }

        function bindTabs() {
            if (bound) {
                return;
            }
            root.addEventListener('click', function (e) {
                var btn = e.target.closest('.finance-ops-tab[data-bs-target]');
                if (!btn || !root.contains(btn)) {
                    return;
                }
                e.preventDefault();
                activateTab(btn);
            });
            bound = true;
        }

        bindTabs();

        var initialBtn = null;
        if (config.initialPane) {
            initialBtn = root.querySelector('.finance-ops-tab[data-bs-target="' + config.initialPane + '"]');
        }
        if (!initialBtn) {
            initialBtn = root.querySelector('.finance-ops-tab.is-active')
                || root.querySelector('.finance-ops-tab[data-bs-target]');
        }

        if (initialBtn) {
            activateTab(initialBtn, true);
            updateUrl(initialBtn.getAttribute('data-bs-target'));
        }
    };
})();
