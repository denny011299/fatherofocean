document.addEventListener('DOMContentLoaded', function () {
    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }
    var shareLinkTemplate = window.TRACKING_STAFF_SHARE_LINK_URL || '';

    $(document).on('click', '.btn-copy-track-link', function () {
        var barangId = $(this).data('barang-id');

        if (!barangId) {
            if (typeof showToast === 'function') {
                showToast('Barang tidak ditemukan.', 'error');
            }
            return;
        }

        if (!shareLinkTemplate) {
            if (typeof showToast === 'function') {
                showToast(t('tracking_endpoint_missing', 'Tracking link endpoint is not configured.'), 'error');
            }
            return;
        }

        var requestUrl = shareLinkTemplate.replace('__BARANG_ID__', String(barangId));

        $.ajax({
            url: requestUrl,
            method: 'GET',
            dataType: 'json',
        })
            .done(function (res) {
                if (!res || !res.url) {
                    showToast('Link tracking tidak tersedia.', 'error');
                    return;
                }

                var url = typeof toAbsoluteUrl === 'function' ? toAbsoluteUrl(res.url) : res.url;

                if (typeof copyTextToClipboard !== 'function') {
                    showToast(t('clipboard_unavailable', 'Clipboard copy is not available.'), 'error');
                    return;
                }

                copyTextToClipboard(url)
                    .then(function () {
                        showToast('Link tracking berhasil disalin', 'success');
                    })
                    .catch(function () {
                        showToast(t('clipboard_copy_failed', 'Failed to copy link to clipboard.'), 'error');
                    });
            })
            .fail(function () {
                showToast(t('tracking_link_failed', 'Failed to generate tracking link.'), 'error');
            });
    });
});
