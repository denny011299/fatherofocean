import './bootstrap';
import './session-guard';
import './daily-rate';
import './sidebar';
import './notifications';
import {
    compressImageFile,
    compressDeliveryTripPhotos,
    initImageCompress,
    replaceInputFile,
    waitForImageCompression,
    BARANG_PRODUCT_COMPRESS,
    BARANG_PRODUCT_INPUT_MAX_BYTES,
    DELIVERY_TRIP_PHOTO_COMPRESS,
    DELIVERY_TRIP_INPUT_MAX_BYTES,
    BARANG_RECEIPT_COMPRESS,
    BARANG_RECEIPT_INPUT_MAX_BYTES,
} from './image-compress';
import Alpine from 'alpinejs';
import {
    initSelect2,
    initCurrencyMask,
    initRmbCurrencyMask,
    initNumberMask,
    initSelectOnFocus,
    getInputNumericValue,
    copyTextToClipboard,
    toAbsoluteUrl,
    syncBootstrapModalState,
    getBootstrapModal,
    hideBootstrapModal,
    formatCbm,
    roundCbm,
    truncateCbm,
    cbmFromDimensions,
    rawCbmFromDimensions,
    CBM_DIMENSION_DIVISOR,
    CBM_DECIMALS,
    roundRmb,
    formatRmbAmount,
    fmtRmb,
    RMB_DECIMALS,
} from './utils';

window.initSelect2 = initSelect2;
window.initCurrencyMask = initCurrencyMask;
window.getInputNumericValue = getInputNumericValue;
window.initRmbCurrencyMask = initRmbCurrencyMask;
window.copyTextToClipboard = copyTextToClipboard;
window.toAbsoluteUrl = toAbsoluteUrl;
window.syncBootstrapModalState = syncBootstrapModalState;
window.getBootstrapModal = getBootstrapModal;
window.hideBootstrapModal = hideBootstrapModal;
window.formatCbm = formatCbm;
window.roundCbm = roundCbm;
window.truncateCbm = truncateCbm;
window.cbmFromDimensions = cbmFromDimensions;
window.rawCbmFromDimensions = rawCbmFromDimensions;
window.CBM_DIMENSION_DIVISOR = CBM_DIMENSION_DIVISOR;
window.fmtCbm = formatCbm;
window.CBM_DECIMALS = CBM_DECIMALS;
window.roundRmb = roundRmb;
window.formatRmbAmount = formatRmbAmount;
window.fmtRmb = fmtRmb;
window.RMB_DECIMALS = RMB_DECIMALS;
window.compressImageFile = compressImageFile;
window.compressDeliveryTripPhotos = compressDeliveryTripPhotos;
window.replaceInputFile = replaceInputFile;
window.waitForImageCompression = waitForImageCompression;
window.BARANG_PRODUCT_COMPRESS = BARANG_PRODUCT_COMPRESS;
window.BARANG_PRODUCT_INPUT_MAX_BYTES = BARANG_PRODUCT_INPUT_MAX_BYTES;
window.DELIVERY_TRIP_PHOTO_COMPRESS = DELIVERY_TRIP_PHOTO_COMPRESS;
window.DELIVERY_TRIP_INPUT_MAX_BYTES = DELIVERY_TRIP_INPUT_MAX_BYTES;
window.BARANG_RECEIPT_COMPRESS = BARANG_RECEIPT_COMPRESS;
window.BARANG_RECEIPT_INPUT_MAX_BYTES = BARANG_RECEIPT_INPUT_MAX_BYTES;

window.initInputMasks = function ($scope) {
    if (!$scope || !$scope.length) {
        return;
    }

    const root = $scope[0];
    initCurrencyMask(root.querySelectorAll('.mask-currency'));
    initRmbCurrencyMask(root.querySelectorAll('.mask-currency-rmb'));
};

window.Alpine = Alpine;
Alpine.start();

document.addEventListener('DOMContentLoaded', () => {
    initImageCompress(document);
});

function hideGlobalPreloader() {
    const preloader = document.getElementById('global-preloader');
    if (!preloader || preloader.dataset.dismissed === '1') {
        return;
    }
    preloader.dataset.dismissed = '1';
    preloader.classList.add('fade-out');
    window.setTimeout(() => preloader.remove(), 600);
}

document.addEventListener('DOMContentLoaded', hideGlobalPreloader);
window.addEventListener('load', () => {
    hideGlobalPreloader();

    initSelect2('.select2', { excludeWithin: '.barang-list-filter-bar' });
    initCurrencyMask('.mask-currency');
    initRmbCurrencyMask('.mask-currency-rmb');
    initNumberMask('.mask-number');
    initSelectOnFocus();
});

$(document).on('shown.bs.modal', '#barangModal', function () {
    initRmbCurrencyMask('#barangModal .mask-currency-rmb');
    initCurrencyMask('#barangModal .mask-currency');
});

$(document).on('shown.bs.modal', '#formModal', function () {
    initCurrencyMask('#formModal .mask-currency');
});

$(document).on('shown.bs.modal', function (e) {
    initSelect2($(e.target).find('.select2').not('#markingRelationWrap .select2, #markingRelationWrap select'), {
        dropdownParent: $(e.target),
    });
});
$(document).on('shown.bs.offcanvas', function (e) {
    initSelect2($(e.target).find('.select2'), {
        dropdownParent: $(e.target),
    });
});

document.addEventListener('hidden.bs.modal', () => {
    window.setTimeout(syncBootstrapModalState, 0);
});

window.showToast = function (message, type = 'success', opts = {}) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const maxStack = opts.maxStack ?? (type === 'info' ? 1 : 3);
    const variant = type === 'error'
        ? 'toast-app--danger'
        : type === 'warning'
            ? 'toast-app--warning'
            : type === 'info'
                ? 'toast-app--info'
                : 'toast-app--success';

    if (type === 'info') {
        container.querySelectorAll('.toast-app--info').forEach((node) => node.remove());
    } else {
        const sameType = container.querySelectorAll('.toast-app.' + variant);
        while (sameType.length >= maxStack) {
            sameType[0].remove();
        }
    }

    const allToasts = container.querySelectorAll('.toast-app');
    while (allToasts.length >= 4) {
        allToasts[0].remove();
    }

    const id = 'toast-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
    const closeClass = type === 'warning' ? 'btn-close btn-close-sm' : 'btn-close btn-close-white btn-close-sm';
    const duration = opts.duration ?? (type === 'info' ? 4200 : type === 'error' ? 5000 : 3800);

    const html = `
        <div id="${id}" class="toast toast-app ${variant} align-items-center border-0 show" role="alert" aria-live="polite">
            <div class="d-flex w-100 align-items-start">
                <div class="toast-body">${message}</div>
                <button type="button" class="${closeClass} flex-shrink-0 mt-1 me-1" data-bs-dismiss="toast" aria-label="Tutup"></button>
            </div>
        </div>`;

    container.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    if (el) {
        el.querySelector('[data-bs-dismiss="toast"]')?.addEventListener('click', () => el.remove());
        setTimeout(() => el.remove(), duration);
    }
};

window.fmtCurrency = function (n) {
    return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
};

window.fmtNumber = function (n, decimals = 0) {
    return Number(n || 0).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
};

window.ajaxPost = function (url, data, opts = {}) {
    return $.ajax({
        url,
        method: opts.method || 'POST',
        data,
        dataType: 'json',
        contentType: opts.contentType,
        processData: opts.processData,
        success(res) {
            showToast(res.message || 'Berhasil');
            if (opts.onSuccess) opts.onSuccess(res);
            if (opts.reload !== false) setTimeout(() => location.reload(), 800);
            if (opts.modal) bootstrap.Modal.getInstance(opts.modal)?.hide();
            if (opts.offcanvas) bootstrap.Offcanvas.getInstance(opts.offcanvas)?.hide();
        },
        error(xhr) {
            const json = xhr.responseJSON;
            if (json?.errors) {
                showToast(Object.values(json.errors).flat().join('\n'), 'error');
            } else {
                showToast(json?.message || 'Terjadi kesalahan', 'error');
            }
            if (opts.onError) opts.onError(xhr);
        },
    });
};

window.notifikasi = function (icon, title, text) {
    Swal.fire({ icon, title, text, confirmButtonColor: '#E88824' });
};

window.confirmDelete = function (text, onConfirm) {
    Swal.fire({
        title: 'Hapus Data?',
        text: text || 'Data yang dihapus tidak bisa dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
    }).then((result) => {
        if (result.isConfirmed && onConfirm) onConfirm();
    });
};

window.initBsTooltips = function (scope) {
    const root = typeof scope === 'string' ? document.querySelector(scope) : (scope || document);
    if (!root || typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
        return;
    }

    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        const instance = bootstrap.Tooltip.getInstance(el);
        if (instance) {
            instance.dispose();
        }
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
};

window.initDataTable = function (selector, columns, opts = {}) {
    return $(selector).DataTable({
        bFilter: true,
        sDom: 'frtlpi',
        ordering: opts.ordering !== false,
        pageLength: opts.pageLength || 25,
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
        columns,
        initComplete: function () {
            var input = $(selector + '_filter input');
            input.addClass('form-control form-control-sm');
            input.attr('style', 'width:250px');
        },
        ...opts,
    });
};
