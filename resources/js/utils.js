/**
 * Reusable utilities for the entire application.
 */

/** Fix orphan modal backdrops / body.modal-open after stacked or static modals close. */
export function syncBootstrapModalState() {
    const openModals = document.querySelectorAll('.modal.show');
    const backdrops = [...document.querySelectorAll('.modal-backdrop')];

    if (openModals.length === 0) {
        backdrops.forEach((backdrop) => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('paddingRight');
        document.querySelectorAll('.modal.modal-under-stack, .modal.modal-stack-top').forEach((modal) => {
            modal.classList.remove('modal-under-stack', 'modal-stack-top');
        });
        return;
    }

    while (backdrops.length > openModals.length) {
        backdrops.pop()?.remove();
    }
}

export function getBootstrapModal(el, options = {}) {
    const element = typeof el === 'string' ? document.querySelector(el) : el;
    if (!element) {
        return null;
    }

    const defaults = { backdrop: 'static', keyboard: false };
    const config = { ...defaults, ...options };

    return bootstrap.Modal.getOrCreateInstance(element, config);
}

export function hideBootstrapModal(el) {
    const element = typeof el === 'string' ? document.querySelector(el) : el;
    if (!element) {
        return;
    }

    const instance = getBootstrapModal(element);
    if (!instance) {
        return;
    }

    const onHidden = () => {
        element.removeEventListener('hidden.bs.modal', onHidden);
        syncBootstrapModalState();
    };
    element.addEventListener('hidden.bs.modal', onHidden);
    instance.hide();
}

export function fmtCurrency(n) {
    return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
}

/** Chinese Yuan (purchase price from China warehouse context). */
export const RMB_DECIMALS = 3;

export function roundRmb(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) {
        return 0;
    }

    return Math.round(n * 1000) / 1000;
}

export function formatRmbAmount(n) {
    return roundRmb(n).toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: RMB_DECIMALS,
    });
}

export function fmtRmb(n) {
    return '¥' + formatRmbAmount(n);
}

export function fmtNumber(n, decimals = 0) {
    return Number(n || 0).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function initSelect2(selector = '.select2', options = {}) {
    const { excludeWithin, ...select2Options } = options;

    $(selector).each(function () {
        const $el = $(this);

        if (excludeWithin && $el.closest(excludeWithin).length) {
            return;
        }

        const modal = $el.closest('.modal');
        if (modal.length && !modal.hasClass('show')) {
            return;
        }

        if ($el.hasClass('select2-hidden-accessible')) {
            $el.select2('destroy');
        }

        const placeholder = select2Options.placeholder
            || $el.data('placeholder')
            || $el.find('option[value=""]').first().text().trim()
            || 'Pilih...';

        $el.select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: select2Options.allowClear !== undefined ? select2Options.allowClear : true,
            placeholder,
            dropdownParent: select2Options.dropdownParent || undefined,
            ...select2Options,
        });
    });
}

function maskTargetElements(selector, root = document) {
    if (typeof selector === 'string') {
        return [...root.querySelectorAll(selector)];
    }

    if (selector instanceof Element) {
        return [selector];
    }

    return [...selector];
}

export function initCurrencyMask(selector = '.mask-currency', root = document) {
    if (!window.Inputmask) {
        return;
    }

    const unmasked = maskTargetElements(selector, root).filter((el) => !el.inputmask);
    if (!unmasked.length) {
        return;
    }

    Inputmask({
        alias: 'numeric',
        groupSeparator: '.',
        radixPoint: ',',
        digits: 0,
        autoGroup: true,
        prefix: 'Rp ',
        removeMaskOnSubmit: true,
        autoUnmask: true,
        rightAlign: false,
    }).mask(unmasked);
}

export function initRmbCurrencyMask(selector = '.mask-currency-rmb', root = document) {
    if (!window.Inputmask) {
        return;
    }

    const unmasked = maskTargetElements(selector, root).filter((el) => !el.inputmask);
    if (!unmasked.length) {
        return;
    }

    Inputmask({
        alias: 'numeric',
        groupSeparator: ',',
        radixPoint: '.',
        digits: RMB_DECIMALS,
        digitsOptional: true,
        autoGroup: true,
        prefix: '¥ ',
        removeMaskOnSubmit: true,
        autoUnmask: true,
        rightAlign: false,
    }).mask(unmasked);
}

export function initNumberMask(selector = '.mask-number') {
    if (window.Inputmask) {
        Inputmask({
            alias: 'numeric',
            groupSeparator: '.',
            radixPoint: ',',
            digits: 2,
            autoGroup: true,
            removeMaskOnSubmit: true,
            autoUnmask: true,
            rightAlign: false,
        }).mask(document.querySelectorAll(selector));
    }
}

export function ajaxSubmit(url, data, options = {}) {
    const defaults = {
        method: 'POST',
        dataType: 'json',
        success(res) {
            window.showToast?.(res.message || 'Berhasil');
            if (options.onSuccess) options.onSuccess(res);
            if (options.reload !== false) setTimeout(() => location.reload(), 800);
        },
        error(xhr) {
            const json = xhr.responseJSON;
            if (json?.errors) {
                const msgs = Object.values(json.errors).flat().join('\n');
                window.showToast?.(msgs, 'error');
            } else {
                window.showToast?.(json?.message || 'Terjadi kesalahan', 'error');
            }
            if (options.onError) options.onError(xhr);
        },
    };
    return $.ajax({ url, data, ...defaults, ...options });
}

export function confirmAction(message = 'Anda yakin?') {
    return confirm(message);
}

export function toAbsoluteUrl(url) {
    if (!url) {
        return '';
    }

    try {
        return new URL(String(url), window.location.origin).href;
    } catch {
        const path = String(url);
        return window.location.origin + (path.startsWith('/') ? path : `/${path}`);
    }
}

const SELECT_ON_FOCUS_ROOTS = ['#barangForm', '#productTable'];

/**
 * Numeric value for masked/plain inputs (Inputmask unmasked when available).
 */
export function getInputNumericValue(input) {
    if (!input) {
        return 0;
    }

    if (input.inputmask) {
        const uv = input.inputmask.unmaskedvalue();
        if (uv === '' || uv === null || uv === undefined) {
            return 0;
        }
        const n = parseFloat(String(uv).replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
    }

    const raw = String(input.value ?? '').trim();
    if (!raw) {
        return 0;
    }

    if (input.type === 'number') {
        const n = parseFloat(raw.replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
    }

    const stripped = raw.replace(/[^\d.,\-]/g, '');
    if (!stripped || stripped === '-' || stripped === ',' || stripped === '.') {
        return 0;
    }

    const normalized = stripped.includes(',') && !stripped.includes('.')
        ? stripped.replace(',', '.')
        : stripped.replace(/\./g, '').replace(',', '.');

    const n = parseFloat(normalized);
    return Number.isFinite(n) ? n : 0;
}

export function isZeroOrEmptyishInput(input) {
    if (!input) {
        return true;
    }

    const raw = String(input.value ?? '').trim();
    if (!raw) {
        return true;
    }

    if (input.inputmask) {
        const uv = input.inputmask.unmaskedvalue();
        if (uv === '' || uv === null || uv === undefined) {
            return true;
        }
    }

    return getInputNumericValue(input) === 0;
}

function isSelectOnFocusEligible(input) {
    if (!(input instanceof HTMLInputElement)) {
        return false;
    }

    if (input.disabled || input.readOnly) {
        return false;
    }

    if (input.matches('.select-on-focus')) {
        return true;
    }

    if (input.type === 'number') {
        return true;
    }

    return input.matches('.mask-currency, .mask-currency-rmb, .mask-number');
}

function isInProductOrDimTable(input) {
    return !!input.closest('#productTable');
}

function shouldSelectAllOnPointerFocus(input) {
    if (input.classList.contains('select-on-focus')) {
        return true;
    }

    if (input.type === 'number' && isInProductOrDimTable(input)) {
        return true;
    }

    if (input.type === 'number' && input.closest('#barangForm')) {
        return true;
    }

    if (input.matches('.mask-currency, .mask-currency-rmb, .mask-number')) {
        return isZeroOrEmptyishInput(input);
    }

    return false;
}

export function selectInputAll(input) {
    if (!input || typeof input.select !== 'function') {
        return;
    }

    const run = () => {
        if (document.activeElement !== input) {
            return;
        }
        try {
            input.select();
        } catch {
            // ignore
        }
        try {
            const len = input.value?.length ?? 0;
            input.setSelectionRange(0, len);
        } catch {
            // ignore (some masked types)
        }
    };

    run();
    setTimeout(run, 0);
}

/**
 * On pointer focus, select all for numeric / zero-ish masked fields in barang forms.
 * Tab navigation is unchanged (no select-all on keyboard focus).
 */
export function initSelectOnFocus(options = {}) {
    if (initSelectOnFocus._initialized) {
        return;
    }
    initSelectOnFocus._initialized = true;

    const roots = options.roots || SELECT_ON_FOCUS_ROOTS;
    const rootSelector = roots.join(', ');
    const pointerTargets = new WeakSet();

    document.addEventListener('mousedown', (e) => {
        if (!(e.target instanceof Element)) {
            return;
        }
        const input = e.target.closest(`${rootSelector} input`);
        if (input && isSelectOnFocusEligible(input)) {
            pointerTargets.add(input);
        }
    }, true);

    document.addEventListener('focusin', (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        if (!input.closest(rootSelector)) {
            return;
        }
        if (!isSelectOnFocusEligible(input)) {
            return;
        }

        const fromPointer = pointerTargets.has(input);
        pointerTargets.delete(input);
        if (!fromPointer) {
            return;
        }

        if (shouldSelectAllOnPointerFocus(input)) {
            selectInputAll(input);
        }
    }, true);
}

export function copyTextToClipboard(text) {
    const value = String(text ?? '');

    const fallbackCopy = () => new Promise((resolve, reject) => {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, value.length);

        try {
            const copied = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (copied) {
                resolve();
                return;
            }
            reject(new Error('Copy command was rejected'));
        } catch (error) {
            document.body.removeChild(textarea);
            reject(error);
        }
    });

    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(value).catch(() => fallbackCopy());
    }

    return fallbackCopy();
}

/** CBM dari dimensi (P×L×T cm): dibagi 1.000.000, lalu {@link truncateCbm}. */
export const CBM_DIMENSION_DIVISOR = 1_000_000;

export function rawCbmFromDimensions(length, width, height, ctns = 1) {
    const l = Number(length) || 0;
    const w = Number(width) || 0;
    const h = Number(height) || 0;
    const c = Math.max(0, Number(ctns) || 0);
    if (l <= 0 || w <= 0 || h <= 0 || c <= 0) {
        return 0;
    }
    return (l * w * h * c) / CBM_DIMENSION_DIVISOR;
}

export function cbmFromDimensions(length, width, height, ctns = 1) {
    return truncateCbm(rawCbmFromDimensions(length, width, height, ctns));
}

/** CBM: 3 decimals — digit ke-4: 0–5 turun, 6–9 naik (angka 5 tidak naik). */
export const CBM_DECIMALS = 3;

export function truncateCbm(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) {
        return 0;
    }
    const scaled4 = Math.floor(n * 10000 + 1e-9);
    const fourthDigit = scaled4 % 10;
    let thousandths = Math.trunc(scaled4 / 10);
    if (fourthDigit >= 6) {
        thousandths++;
    }
    return thousandths / 1000;
}

/** @deprecated Alias of {@link truncateCbm}. */
export function roundCbm(value) {
    return truncateCbm(value);
}

export function formatCbm(value) {
    return truncateCbm(value).toFixed(CBM_DECIMALS);
}
