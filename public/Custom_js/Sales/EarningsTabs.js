(function () {
    'use strict';

    var root = null;
    var content = null;
    var initialized = false;

    function paneHandlers() {
        return {
            '#tab-commission-pane': window.salesEarningsOnCommissionTab,
            '#tab-salary-pane': window.salesEarningsOnSalaryTab,
            '#tab-incentive-pane': window.salesEarningsOnIncentiveTab,
            '#tab-company-payout-pane': window.salesEarningsOnCompanyPayoutTab,
        };
    }

    function syncTabButtons(activeBtn) {
        if (!root) {
            return;
        }
        root.querySelectorAll('.finance-ops-tab').forEach(function (tab) {
            var on = tab === activeBtn;
            tab.classList.toggle('is-active', on);
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function showPane(targetSelector) {
        if (!content || !targetSelector) {
            return;
        }
        content.querySelectorAll('.tab-pane').forEach(function (pane) {
            pane.classList.remove('show', 'active');
        });
        var pane = content.querySelector(targetSelector);
        if (pane) {
            pane.classList.add('show', 'active');
        }
    }

    function runPaneHandler(targetSelector) {
        var fn = paneHandlers()[targetSelector];
        if (typeof fn === 'function') {
            fn();
        }
    }

    function activateTab(btn) {
        if (!btn) {
            return;
        }
        var target = btn.getAttribute('data-bs-target');
        if (!target) {
            return;
        }

        syncTabButtons(btn);
        showPane(target);
        runPaneHandler(target);
    }

    function bindTabs() {
        if (initialized || !root || !content) {
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

        initialized = true;
    }

    function init() {
        root = document.getElementById('salesEarningsTabs');
        content = document.getElementById('salesEarningsTabContent');
        if (!root || !content) {
            return;
        }

        bindTabs();

        var defaultBtn = root.querySelector('.finance-ops-tab.is-active')
            || root.querySelector('.finance-ops-tab[data-bs-target="#tab-commission-pane"]')
            || root.querySelector('.finance-ops-tab');

        if (defaultBtn) {
            activateTab(defaultBtn);
        }
    }

    /* Jalankan setelah Revenue/Salary/Incentive selesai register handler (jQuery ready). */
    if (typeof jQuery !== 'undefined') {
        jQuery(init);
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();
