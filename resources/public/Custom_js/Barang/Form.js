document.addEventListener('DOMContentLoaded', function () {
    var cfg = typeof _barangFormConfig !== 'undefined' ? _barangFormConfig : {};
    var productIdx = 0;
    var additionalPriceIdx = 0;
    var resiList = [];
    var canEditProductLogistics = !!cfg.canEditProductLogistics;
    var productLogisticsSelector = '[data-pf="ctns"], [data-pf="length_cm"], [data-pf="width_cm"], [data-pf="height_cm"]';

    function isFiniteNumber(value) {
        return typeof value === 'number' && isFinite(value);
    }

    function arrayFind(list, predicate) {
        if (!list || !list.length) {
            return undefined;
        }
        if (typeof list.find === 'function') {
            return list.find(predicate);
        }
        for (var i = 0; i < list.length; i += 1) {
            if (predicate(list[i], i, list)) {
                return list[i];
            }
        }
        return undefined;
    }

    function getOptionDataAttr($opt, attrName) {
        if (!$opt || !$opt.length) {
            return null;
        }
        var fromAttr = $opt.attr(attrName);
        if (fromAttr != null && String(fromAttr).trim() !== '') {
            return fromAttr;
        }
        var camel = String(attrName || '')
            .replace(/^data-/, '')
            .replace(/-([a-z])/g, function (_, ch) { return ch.toUpperCase(); });
        return $opt.data(camel) || $opt.data(attrName.replace(/^data-/, '')) || null;
    }

    function selectedMarkingOption($select, event) {
        var $el = $select && $select.length ? $select : $('#markingSelect');
        if (event && event.params && event.params.data && event.params.data.element) {
            return $(event.params.data.element);
        }
        return $el.find(':selected');
    }

    function bindNativeAndSelect2Change($select, handler) {
        if (!$select || !$select.length) {
            return;
        }
        $select.on('change', handler);
        $select.on('select2:select select2:clear', handler);
    }

    function scheduleMarkingPricingSync() {
        window.setTimeout(syncMarkingPricingOnLoad, 0);
        window.setTimeout(syncMarkingPricingOnLoad, 350);
    }

    function productLogisticsTooltip() {
        return t('field_edit_denied', 'Anda tidak memiliki akses mengubah field ini');
    }

    function applyProductLogisticsAccess($context) {
        if (canEditProductLogistics) {
            return;
        }
        ($context || $('#productBody')).find(productLogisticsSelector).each(function () {
            $(this)
                .prop('readonly', true)
                .addClass('barang-product-logistics-locked')
                .attr('title', productLogisticsTooltip())
                .attr('tabindex', '-1');
        });
    }

    function parentRowHasSubRows($parent) {
        var $next = $parent.next();
        return $next.length > 0 && $next.hasClass('product-sub-row');
    }

    function findProductParentRow($row) {
        var $prev = $row.prev();
        while ($prev.length) {
            if (!$prev.hasClass('product-sub-row')) {
                return $prev;
            }
            $prev = $prev.prev();
        }
        return $();
    }

    function calcSubProductRows($parent) {
        var $next = $parent.next();
        while ($next.length && $next.hasClass('product-sub-row')) {
            calcProductRow($next);
            $next = $next.next();
        }
    }

    function parentItemLockTooltip() {
        return t('prod_parent_item_locked', 'Isi nama, foto, PCS, dan RMB di baris sub-item.');
    }

    function syncParentItemFields($parent) {
        var hasSubs = parentRowHasSubRows($parent);
        var $name = $parent.find('[name$="[product_name]"]');
        var $pcs = $parent.find('[data-pf=pcs_per_box]');
        var $rmb = $parent.find('[data-pf=price_per_unit_rmb]');
        var $photoUpload = $parent.find('.product-photo-upload');
        var $photoInput = $parent.find('.product-photo-input');
        var $removePhoto = $parent.find('[name$="[remove_photo]"]');

        if (hasSubs) {
            $name.prop('readonly', true).addClass('product-parent-item-locked').attr('title', parentItemLockTooltip()).val('');
            $pcs.prop('readonly', true).addClass('product-parent-item-locked').val(0);
            $rmb.prop('readonly', true).addClass('product-parent-item-locked');
            setRmbFieldValue($rmb, 0);
            $photoUpload.addClass('product-parent-item-locked');
            $photoInput.prop('disabled', true);
            $removePhoto.val('1');
            $parent.find('.product-photo-path-input').val('');
            syncProductPhotoUi($parent, null);
        } else {
            $name.prop('readonly', false).removeClass('product-parent-item-locked').removeAttr('title');
            $pcs.prop('readonly', false).removeClass('product-parent-item-locked');
            $rmb.prop('readonly', false).removeClass('product-parent-item-locked');
            $photoUpload.removeClass('product-parent-item-locked');
            $photoInput.prop('disabled', false);
            $removePhoto.val('0');
            syncProductPhotoPathInput($parent);
        }

        calcProductRow($parent);
    }

    function syncAllParentItemFields() {
        $('#productBody tr.product-row:not(.product-sub-row)').each(function () {
            syncParentItemFields($(this));
        });
    }

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function toAbsoluteAssetUrl(url) {
        if (!url) {
            return null;
        }
        url = String(url).trim();
        if (!url) {
            return null;
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        if (url.indexOf('//') === 0) {
            return (window.location.protocol || 'https:') + url;
        }
        var origin = window.location.origin || '';
        if (url.charAt(0) === '/') {
            return origin + url;
        }
        return origin + '/' + url;
    }

    function normalizeStoragePath(photoPath) {
        if (!photoPath) {
            return null;
        }
        var path = String(photoPath).replace(/\\/g, '/').replace(/^\/+/, '');
        if (!path) {
            return null;
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        if (path.indexOf('public/') === 0) {
            path = path.slice(7);
        }
        if (path.indexOf('storage/') === 0) {
            path = path.slice(8);
        }
        return path;
    }

    function buildProductPhotoUrlFromPath(photoPath) {
        var path = normalizeStoragePath(photoPath);
        if (!path) {
            return null;
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        if (cfg.storageMediaBaseUrl) {
            var base = String(cfg.storageMediaBaseUrl).replace(/\?.*$/, '');
            return base + '?path=' + encodeURIComponent(path);
        }
        return toAbsoluteAssetUrl('/storage/' + path);
    }

    function resolveProductPhotoUrl(d) {
        d = d || {};
        if (d.has_photo === false) {
            return null;
        }
        if (d.photo_path || d.photoPath) {
            var fromPath = buildProductPhotoUrlFromPath(d.photo_path || d.photoPath);
            if (fromPath) {
                return fromPath;
            }
        }
        var candidates = [d.photo_url, d.photoUrl];
        var i;
        for (i = 0; i < candidates.length; i++) {
            if (candidates[i] && String(candidates[i]).trim()) {
                return toAbsoluteAssetUrl(String(candidates[i]).trim());
            }
        }
        return null;
    }

    function syncProductPhotoPathInput($row) {
        var $input = $row.find('.product-photo-path-input');
        if (!$input.length) {
            return;
        }

        var $upload = $row.find('.product-photo-upload');
        var removePhoto = $row.find('[name$="[remove_photo]"]').val() === '1';
        if (removePhoto) {
            $input.val('');
            return;
        }

        var path = $upload.attr('data-photo-path') || '';
        $input.val(path);
    }

    function syncAllProductPhotoPathInputs() {
        $('#productBody tr.product-row').each(function () {
            syncProductPhotoPathInput($(this));
        });
    }

    function storeProductPhotoMeta($row, d) {
        d = d || {};
        var $upload = $row.find('.product-photo-upload');
        var photoPath = d.photo_path || d.photoPath || '';
        if (photoPath) {
            $upload.attr('data-photo-path', photoPath);
        } else {
            $upload.removeAttr('data-photo-path');
        }
        var serverUrl = resolveProductPhotoUrl(d);
        if (serverUrl && serverUrl.indexOf('blob:') !== 0) {
            $upload.data('serverPhotoUrl', serverUrl);
        } else if (!photoPath) {
            $upload.removeData('serverPhotoUrl');
        }
        syncProductPhotoPathInput($row);
    }

    var PRODUCT_DIMENSIONS = ['length', 'width', 'height'];
    var dimensionProofModalContext = null;
    var dimensionProofModalBlobUrl = null;
    var dimensionProofModalPendingFile = null;
    var dimensionProofUploadInProgress = false;
    var dimensionProofCompressInProgress = false;
    var DIMENSION_PROOF_IMAGE_MAX_PX = 1920;
    var DIMENSION_PROOF_JPEG_QUALITY = 0.84;
    var DIMENSION_PROOF_SKIP_REENCODE_BYTES = 350 * 1024;
    var BARANG_UPLOAD_COMPRESS_CONCURRENCY = 3;
    var BARANG_IMAGE_COMPRESS = window.BARANG_PRODUCT_COMPRESS || {
        maxWidth: 1600,
        maxHeight: 1600,
        quality: 0.78,
        skipBelowBytes: 80_000,
        maxBytes: 5 * 1024 * 1024,
        outputType: 'image/jpeg',
        forceJpeg: true,
    };
    var BARANG_PRODUCT_INPUT_MAX_BYTES = window.BARANG_PRODUCT_INPUT_MAX_BYTES || (10 * 1024 * 1024);
    var BARANG_RECEIPT_COMPRESS = window.BARANG_RECEIPT_COMPRESS || window.DELIVERY_TRIP_PHOTO_COMPRESS || BARANG_IMAGE_COMPRESS;
    var BARANG_RECEIPT_INPUT_MAX_BYTES = window.BARANG_RECEIPT_INPUT_MAX_BYTES || (10 * 1024 * 1024);

    function dimensionProofDataKey(dimension) {
        return 'dimensionProofBlob_' + dimension;
    }

    function resolveDimensionProofUrl(d, dimension) {
        d = d || {};
        if (d['has_' + dimension + '_photo'] === false) {
            return null;
        }
        var urlKey = dimension + '_photo_url';
        var url = d[urlKey];
        if (url && String(url).trim()) {
            return toAbsoluteAssetUrl(String(url).trim());
        }
        var path = d[dimension + '_photo_path'] || d[dimension + '_photoPath'];
        if (path) {
            return buildProductPhotoUrlFromPath(path);
        }
        return null;
    }

    function storeDimensionProofMeta($row, d) {
        d = d || {};
        PRODUCT_DIMENSIONS.forEach(function (dimension) {
            var path = d[dimension + '_photo_path'] || '';
            var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
            if (path) {
                $wrap.attr('data-proof-path', path);
            } else {
                $wrap.removeAttr('data-proof-path');
            }
            syncDimensionProofBtnState($row, dimension);
        });
    }

    function syncDimensionProofBtnState($row, dimension) {
        var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
        var $fileInput = $wrap.find('.dimension-proof-file');
        var hasProof = !!$wrap.attr('data-proof-path')
            || !!$row.data(dimensionProofDataKey(dimension))
            || ($fileInput.length && $fileInput[0].files && $fileInput[0].files.length > 0);
        $wrap.find('.btn-dimension-proof').toggleClass('has-proof', hasProof);
    }

    function revokeDimensionProofBlob($row, dimension) {
        var key = dimensionProofDataKey(dimension);
        var blob = $row.data(key);
        if (blob && String(blob).indexOf('blob:') === 0) {
            URL.revokeObjectURL(blob);
        }
        $row.removeData(key);
    }

    function setFileOnInput(input, file) {
        if (!input) {
            return;
        }
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (err) {
            input.value = '';
        }
    }

    function dimensionProofTitle(dimension) {
        var labels = {
            length: t('dimension_proof_length', 'Panjang (P / L)'),
            width: t('dimension_proof_width', 'Lebar (L / W)'),
            height: t('dimension_proof_height', 'Tinggi (T / H)'),
        };
        return (labels[dimension] || dimension) + ' — ' + t('dimension_proof_view', 'Lihat / upload bukti foto');
    }

    function dimensionFieldHtml(i, dimension, cmField, d) {
        d = d || {};
        var path = d[dimension + '_photo_path'] || '';
        var hasProof = !!path || !!resolveDimensionProofUrl(d, dimension);
        var btnCls = 'btn btn-dimension-proof' + (hasProof ? ' has-proof' : '');
        var pathAttr = path ? ' data-proof-path="' + escapeHtml(path) + '"' : '';
        return '<td><div class="dimension-input-wrap" data-dimension="' + dimension + '"' + pathAttr + '>' +
            '<input type="number" name="products[' + i + '][' + cmField + ']" class="form-control form-control-sm pc" data-pf="' + cmField + '" step="0.01" min="0" value="' + (d[cmField] || 0) + '">' +
            '<button type="button" class="' + btnCls + '" data-dimension="' + dimension + '" title="' + escapeHtml(dimensionProofTitle(dimension)) + '" aria-label="' + escapeHtml(dimensionProofTitle(dimension)) + '">' +
            '<i class="bi bi-camera-fill" aria-hidden="true"></i></button>' +
            '<input type="hidden" name="products[' + i + '][remove_' + dimension + '_photo]" value="0" class="dimension-remove-flag" data-dimension="' + dimension + '">' +
            '<input type="file" name="products[' + i + '][' + dimension + '_photo]" class="d-none dimension-proof-file" data-dimension="' + dimension + '" accept="image/jpeg,image/png,image/webp">' +
            '</div></td>';
    }

    function resolveDimensionProofUrlFromRow($row, dimension) {
        var blob = $row.data(dimensionProofDataKey(dimension));
        if (blob) {
            return blob;
        }
        var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
        var path = $wrap.attr('data-proof-path');
        if (path) {
            return buildProductPhotoUrlFromPath(path);
        }
        return null;
    }

    function syncDimensionProofModalPreview(src) {
        var $preview = $('#dimensionProofPreview');
        var $empty = $('#dimensionProofEmpty');
        if (dimensionProofModalBlobUrl && dimensionProofModalBlobUrl.indexOf('blob:') === 0) {
            URL.revokeObjectURL(dimensionProofModalBlobUrl);
            dimensionProofModalBlobUrl = null;
        }
        if (src) {
            dimensionProofModalBlobUrl = src.indexOf('blob:') === 0 ? src : null;
            $preview.attr('src', src).removeClass('d-none');
            $empty.addClass('d-none');
        } else {
            $preview.attr('src', '').addClass('d-none');
            $empty.removeClass('d-none');
        }
    }

    function getDimensionProofModalEl() {
        return document.getElementById('dimensionProofModal');
    }

    function getDimensionProofBsModal() {
        var el = getDimensionProofModalEl();
        return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
    }

    function getProductIdFromRow($row) {
        var raw = $row.find('input[name$="[id]"]').first().val();
        var id = parseInt(raw, 10);
        return isFiniteNumber(id) && id > 0 ? id : null;
    }

    function getDimensionProofUploadUrl(productId) {
        var barangId = cfg.barangId || $('#barangForm').data('edit-id');
        if (!barangId || !productId) {
            return null;
        }
        var template = cfg.dimensionPhotoUploadUrlTemplate;
        if (template) {
            return String(template).replace('__PRODUCT__', String(productId));
        }
        return '/barang/' + barangId + '/products/' + productId + '/dimension-photo';
    }

    function getProductPhotoUploadUrl(productId) {
        var barangId = cfg.barangId || $('#barangForm').data('edit-id');
        if (!barangId || !productId) {
            return null;
        }
        var template = cfg.productPhotoUploadUrlTemplate;
        if (template) {
            return String(template).replace('__PRODUCT__', String(productId));
        }
        return '/barang/' + barangId + '/products/' + productId + '/photo';
    }

    function getStagedProductPhotoUploadUrl() {
        if (cfg.stagedProductPhotoUploadUrl) {
            return cfg.stagedProductPhotoUploadUrl;
        }
        var barangId = cfg.barangId || $('#barangForm').data('edit-id');
        return barangId ? ('/barang/' + barangId + '/stage-product-photo') : '/barang/stage-product-photo';
    }

    function setProductPhotoUploading($row, uploading) {
        $row.find('.product-photo-upload').toggleClass('is-uploading', !!uploading);
    }

    function applyUploadedProductPhoto($row, res) {
        var photoPath = (res && res.photo_path) ? String(res.photo_path) : '';
        var photoUrl = buildProductPhotoUrlFromPath(photoPath)
            || ((res && res.photo_url) ? toAbsoluteAssetUrl(String(res.photo_url)) : null);
        $row.find('[name$="[remove_photo]"]').val('0');
        $row.find('.product-photo-path-input').val(photoPath);
        if (photoPath) {
            $row.find('.product-photo-upload').attr('data-photo-path', photoPath);
        } else {
            $row.find('.product-photo-upload').removeAttr('data-photo-path');
        }
        $row.find('.product-photo-input').val('');
        syncProductPhotoUi($row, photoUrl || null);
    }

    function uploadProductPhotoToServer($row, file) {
        var productId = getProductIdFromRow($row);
        var url = productId ? getProductPhotoUploadUrl(productId) : getStagedProductPhotoUploadUrl();
        var fd = new FormData();
        fd.append('photo', file);
        return $.ajax({
            url: url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
        });
    }

    function removeProductPhotoOnServer($row) {
        var productId = getProductIdFromRow($row);
        var url = productId ? getProductPhotoUploadUrl(productId) : null;
        if (!url || !$row.find('.product-photo-path-input').val()) {
            return $.when().promise();
        }
        var fd = new FormData();
        fd.append('remove', '1');
        return $.ajax({
            url: url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
        });
    }

    function uploadProductPhotoForRow($row, file) {
        setProductPhotoUploading($row, true);
        return compressBarangImageFile(file)
            .then(function (compressed) {
                return uploadProductPhotoToServer($row, compressed || file);
            })
            .then(function (res) {
                if (!res || res.success === false) {
                    return $.Deferred().reject(res).promise();
                }
                applyUploadedProductPhoto($row, res);
                return res;
            })
            .always(function () {
                setProductPhotoUploading($row, false);
            });
    }

    function collectPendingProductPhotoRows(formEl) {
        var rows = [];
        if (!formEl) {
            return rows;
        }
        formEl.querySelectorAll('.product-photo-input').forEach(function (input) {
            if (!input.files || !input.files.length) {
                return;
            }
            rows.push({ input: input, $row: $(input).closest('.product-row') });
        });
        return rows;
    }

    function uploadPendingProductPhotosBeforeSubmit(formEl) {
        var pending = collectPendingProductPhotoRows(formEl);
        if (!pending.length) {
            return $.when().promise();
        }

        var deferred = $.Deferred();
        var queue = pending.slice();
        var workers = Math.min(BARANG_UPLOAD_COMPRESS_CONCURRENCY, queue.length);
        var active = 0;
        var failed = false;

        function pump() {
            if (failed) {
                return;
            }
            while (active < workers && queue.length) {
                var item = queue.shift();
                active += 1;
                uploadProductPhotoForRow(item.$row, item.input.files[0])
                    .fail(function () {
                        failed = true;
                        deferred.reject();
                    })
                    .always(function () {
                        active -= 1;
                        if (failed) {
                            return;
                        }
                        if (!queue.length && active === 0) {
                            deferred.resolve();
                            return;
                        }
                        pump();
                    });
            }
        }

        pump();
        return deferred.promise();
    }

    function setDimensionProofProgress(percent, visible) {
        var $wrap = $('#dimensionProofProgressWrap');
        var $bar = $('#dimensionProofProgressBar');
        var $pct = $('#dimensionProofProgressPct');
        var pct = Math.max(0, Math.min(100, Math.round(percent || 0)));
        $wrap.toggleClass('d-none', !visible);
        $bar.css('width', pct + '%').attr('aria-valuenow', String(pct));
        $pct.text(pct + '%');
        $wrap.find('.progress').attr('aria-valuenow', String(pct));
    }

    function isDimensionProofModalBusy() {
        return dimensionProofUploadInProgress || dimensionProofCompressInProgress;
    }

    function setDimensionProofCompressing(compressing) {
        dimensionProofCompressInProgress = !!compressing;
        var busy = isDimensionProofModalBusy();
        var $modal = $('#dimensionProofModal');
        $modal.find('#btnDimensionProofClose, #btnDimensionProofModalClose, #btnDimensionProofSave, #btnDimensionProofRemove, #dimensionProofModalFile')
            .prop('disabled', busy);
        if (compressing) {
            $('#dimensionProofProgressLabel').text(t('dimension_proof_compressing', 'Mengompresi…'));
            setDimensionProofProgress(0, true);
        } else if (!dimensionProofUploadInProgress) {
            $('#dimensionProofProgressLabel').text(t('dimension_proof_uploading', 'Mengunggah bukti foto…'));
            setDimensionProofProgress(0, false);
        }
        var bsModal = getDimensionProofBsModal();
        if (bsModal) {
            bsModal._config.backdrop = busy ? 'static' : true;
            bsModal._config.keyboard = !busy;
        }
    }

    function setDimensionProofModalLocked(locked) {
        dimensionProofUploadInProgress = !!locked;
        var busy = isDimensionProofModalBusy();
        var $modal = $('#dimensionProofModal');
        $modal.find('#btnDimensionProofClose, #btnDimensionProofModalClose, #btnDimensionProofSave, #btnDimensionProofRemove, #dimensionProofModalFile')
            .prop('disabled', busy);
        $('#btnDimensionProofSaveSpinner').toggleClass('d-none', !locked);
        $('#btnDimensionProofSaveIcon').toggleClass('d-none', !!locked);
        if (locked) {
            setDimensionProofProgress(0, true);
        } else {
            setDimensionProofProgress(0, false);
        }
        var bsModal = getDimensionProofBsModal();
        if (bsModal) {
            bsModal._config.backdrop = busy ? 'static' : true;
            bsModal._config.keyboard = !busy;
        }
    }

    function isDimensionProofCompressible(file) {
        return !!(file && file.type && /^image\/(jpeg|png|webp)$/i.test(file.type));
    }

    function dimensionProofCompressedFilename(originalName) {
        var base = String(originalName || 'dimension-proof').replace(/\.[^.]+$/, '');
        base = base.replace(/[^\w.\- ]+/g, '_').trim();
        return (base || 'dimension-proof') + '.jpg';
    }

    function releaseDimensionProofImageSource(loaded) {
        if (!loaded) {
            return;
        }
        if (loaded.isBitmap && loaded.source && typeof loaded.source.close === 'function') {
            loaded.source.close();
        }
        if (loaded.objectUrl) {
            URL.revokeObjectURL(loaded.objectUrl);
        }
    }

    function loadDimensionProofImageSource(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file).then(function (bitmap) {
                return { source: bitmap, isBitmap: true };
            });
        }
        return new Promise(function (resolve, reject) {
            var objectUrl = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                resolve({ source: img, isBitmap: false, objectUrl: objectUrl });
            };
            img.onerror = function () {
                URL.revokeObjectURL(objectUrl);
                reject(new Error('image load failed'));
            };
            img.src = objectUrl;
        });
    }

    function compressDimensionProofImage(file) {
        if (!isDimensionProofCompressible(file)) {
            return $.Deferred().resolve(file).promise();
        }

        return $.when(loadDimensionProofImageSource(file).then(function (loaded) {
            var src = loaded.source;
            var w = loaded.isBitmap ? src.width : (src.naturalWidth || src.width);
            var h = loaded.isBitmap ? src.height : (src.naturalHeight || src.height);
            if (!w || !h) {
                releaseDimensionProofImageSource(loaded);
                throw new Error('invalid image dimensions');
            }

            var maxSide = Math.max(w, h);
            var scale = maxSide > DIMENSION_PROOF_IMAGE_MAX_PX
                ? (DIMENSION_PROOF_IMAGE_MAX_PX / maxSide)
                : 1;
            var targetW = Math.max(1, Math.round(w * scale));
            var targetH = Math.max(1, Math.round(h * scale));
            var skipReencode = scale === 1
                && file.type === 'image/jpeg'
                && file.size <= DIMENSION_PROOF_SKIP_REENCODE_BYTES;

            if (skipReencode) {
                releaseDimensionProofImageSource(loaded);
                return file;
            }

            var canvas = document.createElement('canvas');
            canvas.width = targetW;
            canvas.height = targetH;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                releaseDimensionProofImageSource(loaded);
                throw new Error('canvas unavailable');
            }
            ctx.drawImage(src, 0, 0, targetW, targetH);
            releaseDimensionProofImageSource(loaded);

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('compression failed'));
                        return;
                    }
                    resolve(new File(
                        [blob],
                        dimensionProofCompressedFilename(file.name),
                        { type: 'image/jpeg', lastModified: Date.now() },
                    ));
                }, 'image/jpeg', DIMENSION_PROOF_JPEG_QUALITY);
            });
        }));
    }

    function hideDimensionProofModal() {
        if (isDimensionProofModalBusy()) {
            return;
        }
        var bsModal = getDimensionProofBsModal();
        if (bsModal) {
            bsModal.hide();
        }
    }

    function dimensionProofSwalSuccess(title, text) {
        Swal.fire({
            icon: 'success',
            title: title,
            text: text || undefined,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3200,
            timerProgressBar: true,
        });
        window.setTimeout(hideDimensionProofModal, 0);
    }

    function dimensionProofSwalError(title, text) {
        Swal.fire({
            icon: 'error',
            title: title,
            text: text || undefined,
            confirmButtonText: t('understand', 'Mengerti'),
            confirmButtonColor: 'var(--accent, #818cf8)',
        });
    }

    function applyDimensionProofServerMeta($row, dimension, meta) {
        meta = meta || {};
        var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
        var path = meta[dimension + '_photo_path'];
        if (path) {
            $wrap.attr('data-proof-path', path);
            $wrap.find('.dimension-remove-flag').val('0');
        } else {
            $wrap.removeAttr('data-proof-path');
        }
        revokeDimensionProofBlob($row, dimension);
        $wrap.find('.dimension-proof-file').val('');
        syncDimensionProofBtnState($row, dimension);
        var url = meta[dimension + '_photo_url'];
        if (url) {
            syncDimensionProofModalPreview(toAbsoluteAssetUrl(url));
        }
    }

    function openDimensionProofModal($row, dimension) {
        dimensionProofModalContext = { $row: $row, dimension: dimension };
        dimensionProofModalPendingFile = null;
        setDimensionProofModalLocked(false);
        var label = dimensionProofTitle(dimension);
        $('#dimensionProofModalLabel').text(t('dimension_proof_modal_title', 'Bukti foto ukuran') + ' — ' + label.split(' — ')[0]);
        $('#dimensionProofModalFile').val('');
        syncDimensionProofModalPreview(resolveDimensionProofUrlFromRow($row, dimension));
        var bsModal = getDimensionProofBsModal();
        if (bsModal) {
            bsModal._config.backdrop = true;
            bsModal._config.keyboard = true;
            bsModal.show();
        }
    }

    function previewDimensionProofPendingFile(file) {
        if (!file) {
            return;
        }
        if (dimensionProofModalBlobUrl && dimensionProofModalBlobUrl.indexOf('blob:') === 0) {
            URL.revokeObjectURL(dimensionProofModalBlobUrl);
            dimensionProofModalBlobUrl = null;
        }
        dimensionProofModalBlobUrl = URL.createObjectURL(file);
        $('#dimensionProofPreview').attr('src', dimensionProofModalBlobUrl).removeClass('d-none');
        $('#dimensionProofEmpty').addClass('d-none');
    }

    var DIMENSION_PROOF_UPLOAD_PROGRESS_CAP = 90;

    function uploadDimensionProofToServer($row, dimension, file) {
        var productId = getProductIdFromRow($row);
        var url = getDimensionProofUploadUrl(productId);
        if (!url) {
            return $.Deferred().reject({ responseJSON: { message: t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.') } }).promise();
        }
        var fd = new FormData();
        fd.append('dimension', dimension);
        fd.append('photo', file);
        return $.ajax({
            url: url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function (ev) {
                        if (ev.lengthComputable && ev.total > 0) {
                            var ratio = ev.loaded / ev.total;
                            var pct = Math.min(
                                DIMENSION_PROOF_UPLOAD_PROGRESS_CAP,
                                Math.round(ratio * DIMENSION_PROOF_UPLOAD_PROGRESS_CAP),
                            );
                            setDimensionProofProgress(pct, true);
                        } else if (ev.loaded > 0) {
                            setDimensionProofProgress(
                                Math.min(DIMENSION_PROOF_UPLOAD_PROGRESS_CAP - 1, 25),
                                true,
                            );
                        }
                    });
                }
                return xhr;
            },
        });
    }

    function removeDimensionProofOnServer($row, dimension) {
        var productId = getProductIdFromRow($row);
        var url = getDimensionProofUploadUrl(productId);
        if (!url) {
            return $.Deferred().resolve().promise();
        }
        var fd = new FormData();
        fd.append('dimension', dimension);
        fd.append('remove', '1');
        return $.ajax({
            url: url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
        });
    }

    function saveDimensionProof() {
        if (!dimensionProofModalContext || isDimensionProofModalBusy()) {
            return;
        }
        var $row = dimensionProofModalContext.$row;
        var dimension = dimensionProofModalContext.dimension;
        var file = dimensionProofModalPendingFile;
        if (!file) {
            var input = document.getElementById('dimensionProofModalFile');
            file = input && input.files && input.files[0] ? input.files[0] : null;
        }
        if (!file) {
            dimensionProofSwalError(
                t('dimension_proof_file_required', 'Pilih foto terlebih dahulu.'),
                t('dimension_proof_upload', 'Upload / ganti foto'),
            );
            return;
        }

        var uploadUrl = getDimensionProofUploadUrl(getProductIdFromRow($row));
        if (!uploadUrl) {
            var preview = applyDimensionProofFile($row, dimension, file);
            syncDimensionProofModalPreview(preview);
            dimensionProofModalPendingFile = null;
            $('#dimensionProofModalFile').val('');
            dimensionProofSwalSuccess(t('dimension_proof_saved_pending', 'Bukti foto siap. Akan disimpan saat Anda menyimpan barang.'));
            return;
        }

        setDimensionProofModalLocked(true);
        uploadDimensionProofToServer($row, dimension, file)
            .done(function (res) {
                if (!res || res.success === false) {
                    setDimensionProofProgress(0, false);
                    setDimensionProofModalLocked(false);
                    dimensionProofSwalError(
                        t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.'),
                        (res && res.message) || t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.'),
                    );
                    return;
                }
                setDimensionProofProgress(100, true);
                applyDimensionProofServerMeta($row, dimension, res);
                dimensionProofModalPendingFile = null;
                $('#dimensionProofModalFile').val('');
                window.setTimeout(function () {
                    setDimensionProofModalLocked(false);
                    dimensionProofSwalSuccess(
                        res.message || t('dimension_proof_upload_success', 'Bukti foto ukuran berhasil diunggah.'),
                    );
                }, 400);
            })
            .fail(function (xhr) {
                setDimensionProofProgress(0, false);
                setDimensionProofModalLocked(false);
                dimensionProofSwalError(
                    t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.'),
                    formatAjaxErrors(xhr, t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.')),
                );
            });
    }

    function applyDimensionProofFile($row, dimension, file) {
        var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
        var $fileInput = $wrap.find('.dimension-proof-file');
        $wrap.find('.dimension-remove-flag').val('0');
        $wrap.removeAttr('data-proof-path');
        revokeDimensionProofBlob($row, dimension);
        if (file) {
            setFileOnInput($fileInput[0], file);
            var blobUrl = URL.createObjectURL(file);
            $row.data(dimensionProofDataKey(dimension), blobUrl);
            syncDimensionProofBtnState($row, dimension);
            return blobUrl;
        }
        $fileInput.val('');
        syncDimensionProofBtnState($row, dimension);
        return null;
    }

    function removeDimensionProof($row, dimension) {
        var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
        $wrap.find('.dimension-remove-flag').val('1');
        $wrap.find('.dimension-proof-file').val('');
        $wrap.removeAttr('data-proof-path');
        revokeDimensionProofBlob($row, dimension);
        syncDimensionProofBtnState($row, dimension);
        syncDimensionProofModalPreview(null);
    }

    function formatAjaxErrors(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        if (json && json.errors) {
            return Object.values(json.errors).flat().join('\n');
        }
        return (json && json.message) ? json.message : fallback;
    }

    function barangFieldSelector(field) {
        var prodMatch = field.match(/^products\.(\d+)\.(.+)$/);
        if (prodMatch) {
            return '#barangForm [name="products[' + prodMatch[1] + '][' + prodMatch[2] + ']"]';
        }
        var addPriceMatch = field.match(/^additional_prices\.(\d+)\.(.+)$/);
        if (addPriceMatch) {
            return '#barangForm [name="additional_prices[' + addPriceMatch[1] + '][' + addPriceMatch[2] + ']"]';
        }
        return '#barangForm [name="' + field + '"]';
    }

    function clearBarangFormErrors() {
        var $f = $('#barangForm');
        $f.find('.is-invalid').removeClass('is-invalid');
        $f.find('.invalid-feedback').text('');
        $('#barangFormErrors').addClass('d-none');
        $('#barangFormErrorsList').empty();
        $f.find('.select2-hidden-accessible').each(function () {
            $(this).next('.select2-container').find('.select2-selection').removeClass('is-invalid');
        });
    }

    function setBarangFieldError($input, message) {
        if (!$input.length) {
            return;
        }

        $input.addClass('is-invalid');
        if ($input.hasClass('select2-hidden-accessible')) {
            $input.next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }

        var $feedback = $input.siblings('.invalid-feedback');
        if (!$feedback.length && $input.parent().hasClass('input-group')) {
            $feedback = $input.closest('.input-group').siblings('.invalid-feedback');
        }
        if (!$feedback.length) {
            $feedback = $('<div class="invalid-feedback"></div>');
            if ($input.parent().hasClass('input-group')) {
                $input.closest('.input-group').after($feedback);
            } else {
                $input.after($feedback);
            }
        }
        $feedback.text(message);
    }

    function applyBarangValidationErrors(errors) {
        var $f = $('#barangForm');
        var summary = [];
        var $firstInvalid = null;

        Object.keys(errors).forEach(function (field) {
            var messages = errors[field];
            if (!messages || !messages.length) {
                return;
            }

            var message = messages[0];
            summary.push(message);

            var $input = $(barangFieldSelector(field));
            if ($input.length) {
                setBarangFieldError($input, message);
                if (!$firstInvalid) {
                    $firstInvalid = $input;
                }
            }
        });

        var $list = $('#barangFormErrorsList').empty();
        summary.forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });
        $('#barangFormErrors').toggleClass('d-none', summary.length === 0);

        if ($firstInvalid && $firstInvalid.length) {
            var offset = $firstInvalid.offset().top - 120;
            $('html, body').animate({ scrollTop: offset }, 250);
        }

        Swal.fire({
            icon: 'error',
            title: t('incomplete_data', 'Data belum lengkap'),
            html: summary.length
                ? '<ul class="text-start mb-0 ps-3">' + summary.map(function (message) {
                    return '<li>' + $('<div>').text(message).html() + '</li>';
                }).join('') + '</ul>'
                : t('check_form_again', 'Periksa kembali isian formulir.'),
            confirmButtonText: t('understand', 'Mengerti')
        });
    }

    $('#btnAddResi').on('click', addResiFromInput);
    $('#resiInput').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addResiFromInput(); } });

    function handleMarkingSelectChange(event) {
        var $opt = selectedMarkingOption($('#markingSelect'), event);
        var customerId = getOptionDataAttr($opt, 'data-customer-id');
        if (customerId) {
            $('#barangUserId').val(customerId);
        }
        var customerName = getOptionDataAttr($opt, 'data-customer-name');
        if (customerName) {
            $('#barangCustomerName').val(customerName);
        }

        if ($('#barangForm').data('suppress-pricing-fetch')) {
            return;
        }

        applyMarkingPricingFromSelection($opt, { preserveCbmType: false });
    }

    bindNativeAndSelect2Change($('#markingSelect'), handleMarkingSelectChange);

    if (!cfg.initialData) {
        resetProductRows();
        resetAdditionalPriceRows();
    }
    applyProductLogisticsAccess();
    syncAllParentItemFields();
    calcTotals();
    $(document).on('input change blur keyup', '#productBody .pc, #productBody .mask-currency-rmb', function () {
        var $row = $(this).closest('.product-row');
        if (!$row.length) {
            return;
        }
        calcProductRow($row);
        calcProductTotals();
    });
    function appendProductRow(scrollToRow) {
        var $row = $(productRowHtml(productIdx++));
        $('#productBody').append($row);
        syncProductPhotoUi($row, null);
        applyProductLogisticsAccess($row);
        if (typeof initInputMasks === 'function') {
            initInputMasks($row);
        }
        renumberProductRows();
        if (scrollToRow && $row.length && $row[0].scrollIntoView) {
            $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    $(document).on('click', '.js-add-product-row', function () {
        appendProductRow($(this).closest('tfoot').length > 0);
    });
    $('#btnAddAdditionalPriceRow').on('click', function () {
        appendAdditionalPriceRow();
        $('#barangForm').removeData('additional-prices-clear');
    });
    $(document).on('click', '.btn-del-additional-price', function () {
        var $rows = $('#additionalPriceBody .additional-price-row');
        if ($rows.length <= 1) {
            var hadLoaded = !!$('#barangForm').data('additional-prices-loaded');
            var hadId = !!$rows.first().find('[name$="[id]"]').val();
            var hadContent = $rows.first().find('[name$="[name]"]').val()
                || parseCurrency($rows.first().find('.ap-price').val()) > 0;
            if (hadLoaded || hadId || hadContent) {
                $('#barangForm').data('additional-prices-clear', true);
            }
            resetAdditionalPriceRows();
            $('#barangForm').removeData('additional-prices-loaded');
            return;
        }
        $(this).closest('.additional-price-row').remove();
        renumberAdditionalPriceRows();
    });
    $(document).on('click', '.btn-del-product, .btn-del-product-sub', function () {
        var $row = $(this).closest('.product-row');
        if (!$row.hasClass('product-sub-row')) {
            var $next = $row.next();
            while ($next.length && $next.hasClass('product-sub-row')) {
                var $remove = $next;
                $next = $next.next();
                $remove.remove();
            }
        }
        $row.remove();
        renumberProductFieldNames();
        renumberProductRows();
        syncAllParentItemFields();
        calcTotals();
    });
    $(document).on('click', '.btn-add-product-sub', function () {
        var $parent = $(this).closest('.product-row');
        if ($parent.hasClass('product-sub-row')) {
            return;
        }
        var $insertAfter = $parent;
        var $next = $insertAfter.next();
        while ($next.length && $next.hasClass('product-sub-row')) {
            $insertAfter = $next;
            $next = $insertAfter.next();
        }
        var $sub = $(productSubRowHtml(productIdx++));
        $insertAfter.after($sub);
        renumberProductFieldNames();
        renumberProductRows();
        syncParentItemFields($parent);
        if (typeof initInputMasks === 'function') {
            initInputMasks($sub);
        }
        calcProductRow($sub);
    });
    $(document).on('change', '.product-photo-input', function () {
        var $input = $(this);
        var input = this;
        var $row = $input.closest('.product-row');
        delete input.dataset.compressed;
        var file = input.files && input.files[0];
        if (!file) {
            return;
        }
        uploadProductPhotoForRow($row, file)
            .fail(function (xhr) {
                $input.val('');
                syncProductPhotoUi($row, null);
                if (xhr && xhr.message === 'product_photo_too_large') {
                    notifikasi(
                        'error',
                        t('generic_failed', 'Gagal'),
                        t('product_photo_too_large', 'Foto produk melebihi 10 MB.'),
                    );
                    return;
                }
                notifikasi(
                    'error',
                    t('generic_failed', 'Gagal'),
                    formatAjaxErrors(xhr, t('product_photo_upload_failed', 'Gagal mengunggah foto produk.')),
                );
            });
    });
    $(document).on('click', '.product-photo-box', function (e) {
        if ($(e.target).closest('.btn-remove-product-photo').length) {
            return;
        }
        $(this).closest('.product-photo-upload').find('.product-photo-input').trigger('click');
    });
    $(document).on('keydown', '.product-photo-box', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).closest('.product-photo-upload').find('.product-photo-input').trigger('click');
        }
    });
    $(document).on('click', '.btn-dimension-proof', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.product-row');
        if ($row.hasClass('product-sub-row')) {
            return;
        }
        openDimensionProofModal($row, $(this).data('dimension'));
    });
    $(document).on('change', '#dimensionProofModalFile', function () {
        if (!dimensionProofModalContext || dimensionProofUploadInProgress || dimensionProofCompressInProgress) {
            return;
        }
        var file = this.files && this.files[0];
        var $input = this;
        if (!file) {
            dimensionProofModalPendingFile = null;
            return;
        }
        setDimensionProofCompressing(true);
        compressDimensionProofImage(file)
            .done(function (compressed) {
                dimensionProofModalPendingFile = compressed;
                setFileOnInput($input, compressed);
                previewDimensionProofPendingFile(compressed);
            })
            .fail(function () {
                dimensionProofModalPendingFile = file;
                previewDimensionProofPendingFile(file);
                Swal.fire({
                    icon: 'warning',
                    title: t('dimension_proof_compress_failed', 'Gagal mengompresi foto'),
                    text: t('dimension_proof_compress_failed_hint', 'Foto asli akan digunakan.'),
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true,
                });
            })
            .always(function () {
                setDimensionProofCompressing(false);
            });
    });
    $(document).on('click', '#btnDimensionProofSave', function () {
        saveDimensionProof();
    });
    $(document).on('click', '#btnDimensionProofRemove', function () {
        if (!dimensionProofModalContext || isDimensionProofModalBusy()) {
            return;
        }
        var $row = dimensionProofModalContext.$row;
        var dimension = dimensionProofModalContext.dimension;
        var hadServerProof = !!$row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]').attr('data-proof-path');
        var uploadUrl = getDimensionProofUploadUrl(getProductIdFromRow($row));

        function finishRemove() {
            removeDimensionProof($row, dimension);
            dimensionProofModalPendingFile = null;
            $('#dimensionProofModalFile').val('');
            dimensionProofSwalSuccess(t('dimension_proof_removed', 'Bukti foto dihapus.'));
        }

        if (uploadUrl && hadServerProof) {
            setDimensionProofModalLocked(true);
            removeDimensionProofOnServer($row, dimension)
                .done(finishRemove)
                .fail(function (xhr) {
                    dimensionProofSwalError(
                        t('dimension_proof_upload_failed', 'Gagal mengunggah bukti foto.'),
                        formatAjaxErrors(xhr, t('save_failed', 'Gagal menyimpan.')),
                    );
                })
                .always(function () {
                    setDimensionProofModalLocked(false);
                });
            return;
        }

        finishRemove();
    });
    getDimensionProofModalEl()?.addEventListener('hide.bs.modal', function (e) {
        if (isDimensionProofModalBusy()) {
            e.preventDefault();
        }
    });
    getDimensionProofModalEl()?.addEventListener('hidden.bs.modal', function () {
        if (isDimensionProofModalBusy()) {
            return;
        }
        dimensionProofModalContext = null;
        dimensionProofModalPendingFile = null;
        dimensionProofCompressInProgress = false;
        $('#dimensionProofModalFile').val('');
        syncDimensionProofModalPreview(null);
        setDimensionProofModalLocked(false);
    });
    $(document).on('click', '.btn-remove-product-photo', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = $(this).closest('.product-row');
        var hadPath = !!$row.find('.product-photo-path-input').val();

        function finishRemove() {
            $row.find('.product-photo-input').val('');
            $row.find('[name$="[remove_photo]"]').val('1');
            $row.find('.product-photo-path-input').val('');
            syncProductPhotoUi($row, null);
        }

        if (hadPath) {
            setProductPhotoUploading($row, true);
            removeProductPhotoOnServer($row)
                .done(finishRemove)
                .fail(function (xhr) {
                    notifikasi(
                        'error',
                        t('generic_failed', 'Gagal'),
                        formatAjaxErrors(xhr, t('product_photo_upload_failed', 'Gagal mengunggah foto produk.')),
                    );
                })
                .always(function () {
                    setProductPhotoUploading($row, false);
                });
            return;
        }

        finishRemove();
    });
    $('#btnCheckTotal').on('click', calcTotals);
    $('#btnSaveBarang').on('click', submitBarang);

    var PACKING_LIST_EXTENSIONS = ['xls', 'xlsx', 'pdf', 'csv', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'];

    function packingListExtension(fileName) {
        var parts = String(fileName || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function isAllowedPackingListFile(file) {
        if (!file) {
            return false;
        }
        var ext = packingListExtension(file.name);
        return PACKING_LIST_EXTENSIONS.indexOf(ext) !== -1;
    }

    function packingListFileIconClass(ext) {
        var map = {
            xls: 'bi-file-earmark-excel text-success',
            xlsx: 'bi-file-earmark-excel text-success',
            pdf: 'bi-file-earmark-pdf text-danger',
            csv: 'bi-file-earmark-spreadsheet text-info',
            doc: 'bi-file-earmark-word text-primary',
            docx: 'bi-file-earmark-word text-primary',
            png: 'bi-file-earmark-image text-warning',
            jpg: 'bi-file-earmark-image text-warning',
            jpeg: 'bi-file-earmark-image text-warning',
            webp: 'bi-file-earmark-image text-warning',
        };
        return map[ext] || 'bi-file-earmark text-muted';
    }

    function originalPlDisplayName(noIndex, extension) {
        var idx = String(noIndex || $('input[name="no_index"]').val() || '').replace(/[^A-Za-z0-9_-]+/g, '');
        if (!idx) {
            idx = 'INDEX';
        }
        var ext = String(extension || 'pdf').replace(/^\./, '').toLowerCase();
        if (PACKING_LIST_EXTENSIONS.indexOf(ext) === -1) {
            ext = 'pdf';
        }
        return 'ORIGNAL_PL_' + idx + '.' + ext;
    }

    function setOriginalPlExportButton(url) {
        var $btn = $('#btnExportOriginalPl');
        if (url) {
            $btn.attr('href', url).removeClass('d-none');
        } else {
            $btn.addClass('d-none').attr('href', '#');
        }
    }

    function showInvoiceFilePreview(displayName) {
        var ext = packingListExtension(displayName);
        $('#invoiceFileIcon').attr('class', 'bi ' + packingListFileIconClass(ext));
        $('#invoiceFileName').text(displayName || '');
        $('#invoicePreview').removeClass('d-none');
    }

    $('#invoiceFileInput').on('change', function () {
        var file = this.files[0];
        if (!file) {
            resetInvoicePreview();
            return;
        }
        if (!isAllowedPackingListFile(file)) {
            if (typeof showToast === 'function') {
                showToast(t('packing_list_invalid', 'Format packing list tidak didukung.'), 'error');
            }
            $(this).val('');
            resetInvoicePreview();
            return;
        }
        var ext = packingListExtension(file.name);
        showInvoiceFilePreview(originalPlDisplayName($('input[name="no_index"]').val(), ext));
        $('#barangForm').removeData('remove-invoice');
        setOriginalPlExportButton(null);
    });

    $('#btnRemoveInvoice').on('click', function () {
        $('#invoiceFileInput').val('');
        $('#barangForm').data('remove-invoice', true);
        resetInvoicePreview();
        setOriginalPlExportButton(null);
    });

    initGoodReceiptPhotoUi();

    function roundThresholdDownAtFive(value) {
        var n = Number(value);
        if (!isFiniteNumber(n) || n <= 0) {
            return 0;
        }
        var base = Math.floor(n);
        var fraction = n - base;
        return fraction > 0.5 ? base + 1 : base;
    }

    $(document).on('input', '[name=weight_threshold_kg]', function () {
        var current = parseFloat($(this).val());
        if (isFiniteNumber(current)) {
            $(this).val(roundThresholdDownAtFive(current));
        }
        updatePricingCalc();
    });

    $(document).on('change select2:select select2:clear', '[name=cbm_type]', function () {
        if ($('#barangForm').data('suppress-pricing-fetch')) {
            return;
        }
        applyItemPricingFromSelection();
    });

    function isEditingBarang() {
        return !!(cfg.barangId || $('#barangForm').data('edit-id'));
    }

    function applyDailyRate() {
        if (isEditingBarang()) {
            return;
        }
        var rate = window.__dailyRate && window.__dailyRate.rate != null ? window.__dailyRate.rate : null;
        if (rate == null) {
            return;
        }
        var $f = $('#barangForm');
        if (!$f.length) {
            return;
        }
        setCurrencyFieldValue($f.find('[name=rate]'), rate);
    }

    document.addEventListener('daily-rate:updated', function () {
        applyDailyRate();
    });

    if (typeof initInputMasks === 'function') {
        initInputMasks($('#barangForm'));
    }

    if (cfg.initialData) {
        populateForm(cfg.initialData);
    } else {
        updatePricingCalc();
    }

    applyDailyRate();

    window.addEventListener('load', function () {
        if (!cfg.initialData) {
            return;
        }
        var $f = $('#barangForm');
        if (!$f.length) {
            return;
        }
        var gudangId = cfg.initialData.gudang_china_id;
        if (gudangId != null && gudangId !== '') {
            $f.find('[name="gudang_china_id"]').val(String(gudangId));
        }
        syncBarangSelect2Values($f);
    });

    scheduleMarkingPricingSync();

    function populateForm(b) {
        var $f = $('#barangForm');
        $f.data('edit-id', b.id);
        $f.removeData('remove-invoice');
        $f.removeData('remove-good-receipt');
        clearBarangFormErrors();

        var fields = ['gudang_china_id', 'marking', 'customer_name', 'supplier_chinese', 'ctns',
            'price', 'cbm_type', 'rate', 'packing_id',
            'other', 'notes', 'weight_threshold_kg'];
        fields.forEach(function (f) {
            var val = b[f];
            var $input = $f.find('[name="' + f + '"]');
            if (!$input.length) {
                return;
            }
            if ((val === null || val === undefined || val === '') && f === 'gudang_china_id' && $input.val()) {
                return;
            }
            $input.val(val);
        });
        setRmbFieldValue($f.find('[name=price]'), b.price);
        if (b.rate != null && b.rate !== '') {
            setCurrencyFieldValue($f.find('[name=rate]'), b.rate);
        }
        $f.data('suppress-pricing-fetch', true);
        syncBarangSelect2Values($f);
        $f.removeData('suppress-pricing-fetch');
        hydrateSnapshotPricingFields(b);

        if (b.user_id) {
            $('#barangUserId').val(b.user_id);
        }
        if (b.tanggal_masuk) {
            $f.find('[name=tanggal_masuk]').val(b.tanggal_masuk.split('T')[0]);
        }

        setResiList(b.no_resi || []);
        fillAdditionalPriceRows(b.additional_prices || []);
        fillProductRows(b.products || []);
        calcTotals();
        showExportTerimaPdfButton(
            b.terima_barang_pdf_url
                || buildTerimaBarangPdfUrl(b.id || cfg.barangId),
        );
        showExportShippingLabelButton(
            b.shipping_label_pdf_url || buildShippingLabelPdfUrl(b.id || cfg.barangId),
        );
        showExportProductsExcelButton(
            b.products_export_excel_url || buildProductsExportExcelUrl(b.id || cfg.barangId),
        );

        revokeGoodReceiptObjectUrl();
        $('#goodReceiptFileInput').val('');
        if (b.good_receipt_path) {
            $('#goodReceiptUpload').attr('data-good-receipt-path', b.good_receipt_path);
        }
        var goodReceiptUrl = resolveGoodReceiptPhotoUrl(b);
        if (!goodReceiptUrl) {
            var domPath = $('#goodReceiptUpload').attr('data-good-receipt-path');
            if (domPath) {
                goodReceiptUrl = buildGoodReceiptUrlFromPath(domPath);
            }
        }
        if (goodReceiptUrl) {
            $f.data('existing-good-receipt', b.good_receipt_path || true);
            syncGoodReceiptPhotoUi(goodReceiptUrl);
        } else {
            syncGoodReceiptPhotoUi(null);
            $('#goodReceiptUpload').removeAttr('data-good-receipt-path');
            $f.removeData('existing-good-receipt');
        }

        resetInvoicePreview();
        if (b.invoice_file_url || b.invoice_file) {
            $f.data('existing-invoice', b.invoice_file);
            var storedName = b.invoice_file ? String(b.invoice_file).split('/').pop() : '';
            var displayName = storedName || originalPlDisplayName(b.no_index, (storedName.split('.').pop() || 'xlsx'));
            showInvoiceFilePreview(displayName);
            setOriginalPlExportButton(b.original_pl_download_url || cfg.originalPlDownloadUrl || null);
        } else {
            setOriginalPlExportButton(null);
        }

        updatePricingCalc();
    }

    function unmaskRmbFieldsForSubmit($f, fd) {
        $f.find('.mask-currency-rmb').each(function () {
            var $input = $(this);
            var name = $input.attr('name');
            if (!name) {
                return;
            }
            fd.set(name, String(getRmbInputValue($input)));
        });
    }

    function unmaskAdditionalPriceFieldsForSubmit($f, fd) {
        $f.find('.ap-price').each(function () {
            var $input = $(this);
            var name = $input.attr('name');
            if (!name) {
                return;
            }
            var amount = 0;
            var el = $input[0];
            if (el && typeof window.getInputNumericValue === 'function') {
                amount = window.getInputNumericValue(el) || 0;
            } else if (el && el.inputmask && typeof el.inputmask.unmaskedvalue === 'function') {
                amount = parseFloat(String(el.inputmask.unmaskedvalue() || '').replace(',', '.')) || 0;
            } else {
                amount = parseCurrency($input.val());
            }
            fd.set(name, String(amount));
        });
    }

    function additionalPriceRowsAreBlank($f) {
        var blank = true;
        $f.find('#additionalPriceBody .additional-price-row').each(function () {
            var $row = $(this);
            if ($row.find('[name$="[id]"]').val()) {
                blank = false;
                return false;
            }
            if (String($row.find('[name$="[name]"]').val() || '').trim() !== '') {
                blank = false;
                return false;
            }
            var $price = $row.find('.ap-price');
            var amount = 0;
            var el = $price[0];
            if (el && typeof window.getInputNumericValue === 'function') {
                amount = window.getInputNumericValue(el) || 0;
            } else {
                amount = parseCurrency($price.val());
            }
            if (amount > 0) {
                blank = false;
                return false;
            }
        });
        return blank;
    }

    function stripAdditionalPriceFieldsFromFormData(fd) {
        if (typeof fd.keys !== 'function') {
            return;
        }
        var keys = [];
        fd.keys().forEach(function (key) {
            if (String(key).indexOf('additional_prices') === 0) {
                keys.push(key);
            }
        });
        keys.forEach(function (key) {
            fd.delete(key);
        });
    }

    function normalizeMarkingPriceFieldForSubmit($f, fieldName) {
        var $input = $f.find('[name=' + fieldName + ']');
        if (!$input.length) {
            return;
        }
        if ($input.hasClass('barang-marking-price-unset')) {
            $input.val('0');
            return;
        }
        if ($input.inputmask) {
            $input.val(String(parseCurrency($input.val())));
        }
    }

    function normalizeMarkingPricesForSubmit($f) {
        normalizeMarkingPriceFieldForSubmit($f, 'price_per_cbm');
        normalizeMarkingPriceFieldForSubmit($f, 'weight_price');
    }

    function syncBarangSelect2Values($f) {
        $f.find('select.select2').each(function () {
            var $select = $(this);
            if (!$select.length) {
                return;
            }
            var value = $select.val();
            if (value == null) {
                value = '';
            }
            $select.val(value);
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.trigger('change.select2');
            }
        });
    }

    /** Skip empty product photo inputs — speeds up validation on forms with many rows. */
    function collectProductsFromForm($f) {
        var rows = [];
        $('#productBody tr.product-row').each(function () {
            var $row = $(this);
            var row = {};
            var isSub = $row.hasClass('product-sub-row');
            if (isSub) {
                row.is_sub_item = 1;
            }

            $row.find('[name]').each(function () {
                var el = this;
                if (!el.name || el.name.indexOf('products[') !== 0) {
                    return;
                }
                if (el.type === 'file') {
                    return;
                }
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                    return;
                }
                var match = el.name.match(/products\[\d+]\[([^\]]+)\]/);
                if (!match) {
                    return;
                }
                var key = match[1];
                var value = el.value;
                if (key === 'price_per_unit_rmb') {
                    value = String(getRmbInputValue($(el)));
                }
                row[key] = value;
            });

            PRODUCT_DIMENSIONS.forEach(function (dimension) {
                var $wrap = $row.find('.dimension-input-wrap[data-dimension="' + dimension + '"]');
                var proofPath = $wrap.attr('data-proof-path');
                if (proofPath) {
                    row[dimension + '_photo_path'] = proofPath;
                }
                var removeFlag = $wrap.find('.dimension-remove-flag').val();
                if (removeFlag === '1') {
                    row['remove_' + dimension + '_photo'] = '1';
                }
            });

            if (!isSub && row.is_sub_item == null) {
                row.is_sub_item = 0;
            }

            rows.push(row);
        });

        return rows;
    }

    function isProductFormField(name) {
        return /^products\[\d+\]\[/.test(name);
    }

    function isProductUploadField(name) {
        return /^products\[\d+\]\[(photo|length_photo|width_photo|height_photo)\]$/.test(name);
    }

    /** Scalar product fields go in products_payload JSON to avoid PHP max_input_vars truncation. */
    function buildBarangFormData(formEl) {
        var fd = new FormData();
        Array.prototype.forEach.call(formEl.elements, function (el) {
            if (!el.name || el.disabled) {
                return;
            }
            if (isProductFormField(el.name)) {
                if (el.type === 'file' && isProductUploadField(el.name)) {
                    if (!el.files || !el.files.length) {
                        return;
                    }
                    Array.prototype.forEach.call(el.files, function (file) {
                        fd.append(el.name, file);
                    });
                }
                return;
            }
            if (el.type === 'file') {
                if (el.files && el.files.length) {
                    Array.prototype.forEach.call(el.files, function (file) {
                        fd.append(el.name, file);
                    });
                }
                return;
            }
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                return;
            }
            if (el.type === 'select-multiple') {
                Array.prototype.forEach.call(el.selectedOptions, function (opt) {
                    fd.append(el.name, opt.value);
                });
                return;
            }
            var value = el.value;
            if (el.tagName === 'SELECT' && $(el).hasClass('select2-hidden-accessible')) {
                value = $(el).val();
                if (value == null) {
                    value = '';
                }
            }
            fd.append(el.name, value);
        });
        return fd;
    }

    function setBarangFormBusy(busy, statusLabel) {
        var $save = $('#btnSaveBarang');
        var $spinner = $('#btnSaveBarangSpinner');
        var $icon = $('#btnSaveBarangIcon');
        var $label = $('#btnSaveBarangLabel');
        if (!$save.length) {
            return;
        }
        $save.prop('disabled', !!busy);
        $spinner.toggleClass('d-none', !busy);
        $icon.toggleClass('d-none', !!busy);
        if ($label.length) {
            if (!$save.data('default-label')) {
                $save.data('default-label', $label.text());
            }
            if (busy && statusLabel) {
                $label.text(statusLabel);
            } else {
                $label.text($save.data('default-label') || t('save', 'Simpan'));
            }
        }
        $('.form-sticky-footer-inner a.btn').each(function () {
            var $link = $(this);
            if (busy) {
                $link.addClass('disabled pe-none').attr('aria-disabled', 'true').attr('tabindex', '-1');
            } else {
                $link.removeClass('disabled pe-none').removeAttr('aria-disabled').removeAttr('tabindex');
            }
        });
    }

    function compressBarangImageFile(file, options) {
        if (!file || typeof window.compressImageFile !== 'function') {
            return $.when(file).promise();
        }
        if (file.size > BARANG_PRODUCT_INPUT_MAX_BYTES) {
            return $.Deferred().reject({ message: 'product_photo_too_large' }).promise();
        }
        return $.when(window.compressImageFile(file, options || BARANG_IMAGE_COMPRESS));
    }

    function compressGoodReceiptImageFile(file) {
        if (file && file.size > BARANG_RECEIPT_INPUT_MAX_BYTES) {
            return $.Deferred().reject({ message: 'good_receipt_too_large' }).promise();
        }
        return compressBarangImageFile(file, BARANG_RECEIPT_COMPRESS);
    }

    function compressFileInput(input) {
        if (!input || !input.files || !input.files.length) {
            return $.when().promise();
        }
        var file = input.files[0];
        var compressPromise = input.id === 'goodReceiptFileInput'
            ? compressGoodReceiptImageFile(file)
            : compressBarangImageFile(file);
        return compressPromise.then(function (compressed) {
            if (compressed && compressed !== file) {
                setFileOnInput(input, compressed);
            }
            input.dataset.compressed = '1';
        });
    }

    function collectBarangUploadInputs(formEl) {
        var inputs = [];
        if (!formEl) {
            return inputs;
        }
        formEl.querySelectorAll('.dimension-proof-file').forEach(function (input) {
            if (input.files && input.files.length) {
                inputs.push(input);
            }
        });
        var goodReceipt = document.getElementById('goodReceiptFileInput');
        if (goodReceipt && goodReceipt.files && goodReceipt.files.length) {
            inputs.push(goodReceipt);
        }
        return inputs;
    }

    function prepareBarangUploadsBeforeSubmit(formEl) {
        return uploadPendingProductPhotosBeforeSubmit(formEl).then(function () {
            return compressAllBarangUploadsBeforeSubmit(formEl);
        });
    }

    function compressAllBarangUploadsBeforeSubmit(formEl) {
        var inputs = collectBarangUploadInputs(formEl);
        if (!inputs.length) {
            return $.when().promise();
        }

        var deferred = $.Deferred();
        var queue = inputs.slice();
        var workers = Math.min(BARANG_UPLOAD_COMPRESS_CONCURRENCY, queue.length);
        var active = 0;
        var failed = false;

        function pump() {
            if (failed) {
                return;
            }
            while (active < workers && queue.length) {
                var input = queue.shift();
                active += 1;
                compressFileInput(input)
                    .fail(function () {
                        failed = true;
                        deferred.reject();
                    })
                    .always(function () {
                        active -= 1;
                        if (failed) {
                            return;
                        }
                        if (!queue.length && active === 0) {
                            deferred.resolve();
                            return;
                        }
                        pump();
                    });
            }
        }

        pump();
        return deferred.promise();
    }

    function buildBarangSubmitFormData($f, productsPayload, editId) {
        var fd = new FormData();
        fd.append('products_payload', JSON.stringify(productsPayload));
        var formFd = buildBarangFormData($f[0]);
        if (typeof formFd.forEach === 'function') {
            formFd.forEach(function (value, key) {
                fd.append(key, value);
            });
        }
        fd.set('no_resi', JSON.stringify(resiList));
        unmaskRmbFieldsForSubmit($f, fd);
        unmaskAdditionalPriceFieldsForSubmit($f, fd);
        // Blank placeholder only: omit so server preserves existing additional fees.
        if (additionalPriceRowsAreBlank($f) && !$f.data('additional-prices-clear')) {
            stripAdditionalPriceFieldsFromFormData(fd);
        } else if ($f.data('additional-prices-clear') && additionalPriceRowsAreBlank($f)) {
            stripAdditionalPriceFieldsFromFormData(fd);
            fd.append('additional_prices_clear', '1');
        }
        if (editId) {
            fd.append('_method', 'PUT');
        }
        if ($f.data('remove-invoice')) {
            fd.append('remove_invoice_file', '1');
        }
        if ($f.data('remove-good-receipt')) {
            fd.append('remove_good_receipt_file', '1');
        }
        var goodReceiptEl = document.getElementById('goodReceiptFileInput');
        if (goodReceiptEl && goodReceiptEl.files && goodReceiptEl.files.length) {
            fd.set('good_receipt_file', goodReceiptEl.files[0]);
        }
        return fd;
    }

    function sendBarangFormRequest($f, productsPayload, editId, isEditingExisting) {
        var url = editId ? (cfg.updateUrl || '/barang/' + editId) : (cfg.storeUrl || '/barang');
        var fd = buildBarangSubmitFormData($f, productsPayload, editId);
        var keepFooterBusy = false;

        return $.ajax({
            url: url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': token },
        }).done(function (res) {
            if (!isEditingExisting) {
                if (res.products && res.products.length) {
                    fillProductRows(res.products);
                    calcTotals();
                }
                if (res.additional_prices) {
                    fillAdditionalPriceRows(res.additional_prices);
                }
                if (res.data && res.data.id) {
                    $f.data('edit-id', res.data.id);
                    var newId = res.data.id;
                    showExportTerimaPdfButton(
                        res.terima_barang_pdf_url
                            || buildTerimaBarangPdfUrl(newId),
                    );
                    showExportProductsExcelButton(
                        res.products_export_excel_url || buildProductsExportExcelUrl(newId),
                    );
                    showExportShippingLabelButton(
                        res.shipping_label_pdf_url || buildShippingLabelPdfUrl(newId),
                    );
                    openShippingLabelTab(
                        res.shipping_label_pdf_url || buildShippingLabelPdfUrl(newId),
                    );
                } else {
                    if (res.terima_barang_pdf_url || res.data?.id) {
                        showExportTerimaPdfButton(
                            res.terima_barang_pdf_url
                                || buildTerimaBarangPdfUrl(res.data && res.data.id),
                        );
                    }
                    if (res.products_export_excel_url || (res.data && res.data.id)) {
                        showExportProductsExcelButton(
                            res.products_export_excel_url
                                || buildProductsExportExcelUrl(res.data && res.data.id),
                        );
                    }
                    if (res.shipping_label_pdf_url) {
                        showExportShippingLabelButton(res.shipping_label_pdf_url);
                        openShippingLabelTab(res.shipping_label_pdf_url);
                    }
                }
                if (res.original_pl_download_url) {
                    cfg.originalPlDownloadUrl = res.original_pl_download_url;
                    $('#barangForm').data('existing-invoice', true);
                    setOriginalPlExportButton(res.original_pl_download_url);
                    var plExt = ($('#invoiceFileInput')[0].files[0] || {}).name;
                    plExt = plExt ? plExt.split('.').pop() : 'xlsx';
                    showInvoiceFilePreview(originalPlDisplayName($('input[name="no_index"]').val(), plExt));
                }
            }

            var redirectUrl = res.redirect || cfg.indexUrl || '/barang';
            keepFooterBusy = true;
            if (res.message) {
                var flashType = 'success';
                if (isEditingExisting) {
                    flashType = res.submitted_for_approval
                        ? 'success'
                        : (res.saved_directly ? 'info' : 'success');
                }
                storeBarangFormFlash(res.message, flashType);
            }
            window.location.href = redirectUrl;
        }).fail(function (xhr) {
            var json = xhr.responseJSON;
            if (json && json.errors) {
                applyBarangValidationErrors(json.errors);
                return;
            }
            notifikasi('error', t('generic_failed', 'Gagal'), formatAjaxErrors(xhr, t('save_failed', 'Gagal menyimpan.')));
        }).always(function () {
            if (!keepFooterBusy) {
                setBarangFormBusy(false);
            }
        });
    }

    function validateProductPhotosReady() {
        var missing = false;
        $('#productBody tr.product-row').each(function () {
            var $row = $(this);
            var $upload = $row.find('.product-photo-upload');
            if (!$upload.hasClass('has-photo')) {
                return;
            }
            var path = $.trim($row.find('.product-photo-path-input').val() || '');
            var fileInput = $row.find('.product-photo-input')[0];
            var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
            if (!path && !hasFile) {
                missing = true;
            }
        });

        if (missing) {
            return $.Deferred().reject({ message: 'product_photo_not_uploaded' }).promise();
        }

        return $.when().promise();
    }

    function submitBarang() {
        var $f = $('#barangForm');
        clearBarangFormErrors();
        normalizeMarkingPricesForSubmit($f);
        addResiFromInput();
        renderResiTags();
        renumberProductFieldNames();
        syncAllParentItemFields();
        syncAllProductPhotoPathInputs();
        syncBarangSelect2Values($f);
        var editId = $f.data('edit-id') || cfg.barangId;
        var isEditingExisting = !!editId;

        setBarangFormBusy(true, t('save_uploading_photos', 'Mengunggah foto…'));
        prepareBarangUploadsBeforeSubmit($f[0])
            .then(function () {
                return validateProductPhotosReady();
            })
            .then(function () {
                setBarangFormBusy(true, t('save_uploading', 'Menyimpan…'));
                syncAllProductPhotoPathInputs();
                var productsPayload = collectProductsFromForm($f);
                return sendBarangFormRequest($f, productsPayload, editId, isEditingExisting);
            })
            .fail(function (err) {
                setBarangFormBusy(false);
                if (err && err.message === 'product_photo_not_uploaded') {
                    notifikasi(
                        'error',
                        t('generic_failed', 'Gagal'),
                        t('product_photo_not_uploaded', 'Foto produk belum terunggah. Pilih ulang foto dan tunggu hingga selesai.'),
                    );
                    return;
                }
                notifikasi(
                    'error',
                    t('generic_failed', 'Gagal'),
                    t('product_photo_upload_failed', 'Gagal mengunggah foto produk.'),
                );
            });
    }

    function productPhotoCellHtml(i, d, options) {
        d = d || {};
        options = options || {};
        var photoUrl = resolveProductPhotoUrl(d);
        var photoPath = d.photo_path || d.photoPath || '';
        var hasPhoto = !!photoUrl || !!photoPath;
        if (!photoUrl && photoPath) {
            photoUrl = buildProductPhotoUrlFromPath(photoPath);
        }
        var idField = (!options.skipId && d.id)
            ? '<input type="hidden" name="products[' + i + '][id]" value="' + d.id + '">'
            : '';
        var uploadCls = 'product-photo-upload' + (hasPhoto ? ' has-photo' : '');
        var placeholderCls = 'product-photo-placeholder' + (hasPhoto ? ' d-none' : '');
        var removeCls = 'btn-remove-product-photo' + (hasPhoto ? '' : ' d-none');
        var uploadLabel = hasPhoto ? t('change_photo', 'Change photo') : t('upload_photo', 'Upload photo');
        var dataPathAttr = photoPath ? ' data-photo-path="' + escapeHtml(photoPath) + '"' : '';
        var removeLabel = t('remove', 'Remove');
        var imgTag = hasPhoto
            ? '<img src="' + escapeHtml(photoUrl) + '" class="product-photo-preview" alt="" loading="lazy" decoding="async">'
            : '';

        return '<td class="product-photo-cell">' + idField +
            '<input type="hidden" name="products[' + i + '][remove_photo]" value="0">' +
            '<input type="hidden" name="products[' + i + '][photo_path]" class="product-photo-path-input" value="' + escapeHtml(photoPath) + '">' +
            '<div class="' + uploadCls + '"' + dataPathAttr + '">' +
            '<input type="file" name="products[' + i + '][photo]" class="product-photo-input" accept="image/*">' +
            '<div class="product-photo-box" role="button" tabindex="0" aria-label="' + uploadLabel + '">' +
            '<span class="' + placeholderCls + '" aria-hidden="true">+</span>' +
            imgTag +
            '<button type="button" class="' + removeCls + '" aria-label="' + removeLabel + '"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
            '</div></div></td>';
    }

    function syncProductPhotoUi($row, src) {
        var $upload = $row.find('.product-photo-upload');
        var $preview = $row.find('.product-photo-preview');
        var $placeholder = $row.find('.product-photo-placeholder');
        var $btnRemove = $row.find('.btn-remove-product-photo');
        var $box = $row.find('.product-photo-box');
        var hasPhoto = !!src;
        var previousSrc = $preview.length ? ($preview.attr('src') || '') : '';

        if (previousSrc && previousSrc.indexOf('blob:') === 0) {
            URL.revokeObjectURL(previousSrc);
        }

        if ($preview.length) {
            $preview.off('error.barangPhoto').on('error.barangPhoto', function () {
                var failedSrc = $(this).attr('src') || '';
                if (failedSrc.indexOf('blob:') === 0) {
                    $(this).off('error.barangPhoto');
                    syncProductPhotoUi($row, null);
                    return;
                }
                var path = $upload.attr('data-photo-path');
                var fallback = path ? buildProductPhotoUrlFromPath(path) : ($upload.data('serverPhotoUrl') || null);
                if (fallback && fallback !== failedSrc) {
                    $(this).attr('src', fallback);
                    return;
                }
                $(this).off('error.barangPhoto');
                if (!$upload.attr('data-photo-path')) {
                    syncProductPhotoUi($row, null);
                }
            });
        }

        if (hasPhoto) {
            var displaySrc = src.indexOf('blob:') === 0 ? src : (toAbsoluteAssetUrl(src) || src);
            if (src.indexOf('blob:') !== 0) {
                $upload.data('serverPhotoUrl', displaySrc);
            }
            if (!$preview.length) {
                $box.prepend('<img class="product-photo-preview" alt="">');
                $preview = $row.find('.product-photo-preview');
            }
            $preview.attr('src', displaySrc).removeClass('d-none');
            $placeholder.addClass('d-none');
            $upload.addClass('has-photo');
            $btnRemove.removeClass('d-none');
            $box.attr('aria-label', t('change_photo', 'Change photo'));
        } else {
            $preview.remove();
            $placeholder.removeClass('d-none');
            $upload.removeClass('has-photo');
            $btnRemove.addClass('d-none');
            $upload.removeAttr('data-photo-path').removeData('serverPhotoUrl');
            $box.attr('aria-label', t('upload_photo', 'Upload photo'));
        }

        syncProductPhotoPathInput($row);
    }

    function additionalPriceRowHtml(i, d) {
        d = d || {};
        var idField = d.id
            ? '<input type="hidden" name="additional_prices[' + i + '][id]" value="' + d.id + '">'
            : '';
        return '<tr class="additional-price-row">' +
            '<td class="text-muted text-center additional-price-row-num">' + (i + 1) + '</td>' +
            '<td>' + idField +
            '<input type="text" name="additional_prices[' + i + '][name]" class="form-control form-control-sm" value="' + escapeHtml(d.name || '') + '" placeholder="' + escapeHtml(t('additional_price_name_placeholder', 'Nama biaya...')) + '"></td>' +
            '<td><input type="text" name="additional_prices[' + i + '][price]" class="form-control form-control-sm mask-currency ap-price" value="' + escapeHtml(String(d.price || '')) + '"></td>' +
            '<td><button type="button" class="btn btn-sm text-danger btn-del-additional-price"><i class="bi bi-dash-circle"></i></button></td>' +
            '</tr>';
    }

    function appendAdditionalPriceRow(d) {
        var $row = $(additionalPriceRowHtml(additionalPriceIdx++, d || {}));
        $('#additionalPriceBody').append($row);
        // Init mask first, then set value — otherwise Inputmask resets the field to 0.
        if (typeof initInputMasks === 'function') {
            initInputMasks($row);
        }
        setCurrencyFieldValue($row.find('.ap-price'), (d && d.price) || 0);
    }

    function resetAdditionalPriceRows() {
        additionalPriceIdx = 0;
        $('#additionalPriceBody').empty();
        appendAdditionalPriceRow();
    }

    function fillAdditionalPriceRows(rows) {
        additionalPriceIdx = 0;
        $('#additionalPriceBody').empty();
        if (!rows || !rows.length) {
            appendAdditionalPriceRow();
            $('#barangForm').removeData('additional-prices-loaded');
            return;
        }
        $('#barangForm').data('additional-prices-loaded', true);
        rows.forEach(function (row) {
            appendAdditionalPriceRow(row);
        });
    }

    function renumberAdditionalPriceRows() {
        $('#additionalPriceBody .additional-price-row').each(function (idx) {
            $(this).find('.additional-price-row-num').text(idx + 1);
        });
    }

    function productSubRowHtml(i, d) {
        d = d || {};
        var idField = d.id ? '<input type="hidden" name="products[' + i + '][id]" value="' + d.id + '">' : '';
        var skipCell = '<td class="product-sub-skip"></td>';

        return '<tr class="product-row product-sub-row">' +
            '<td class="product-row-num-cell product-row-num-cell--sub"><span class="product-row-num-label"></span></td>' +
            '<td class="product-sub-btn-cell"></td>' +
            '<td>' + idField +
            '<input type="hidden" name="products[' + i + '][is_sub_item]" value="1">' +
            '<input type="text" name="products[' + i + '][product_name]" class="form-control form-control-sm" value="' + escapeHtml(d.product_name || '') + '" style="min-width:140px"></td>' +
            skipCell +
            productPhotoCellHtml(i, d, { skipId: true }) +
            skipCell +
            '<td><input type="number" name="products[' + i + '][pcs_per_box]" class="form-control form-control-sm pc" data-pf="pcs_per_box" min="0" value="' + (d.pcs_per_box || 0) + '" style="min-width:70px"></td>' +
            skipCell + skipCell + skipCell + skipCell + skipCell +
            '<td><input type="text" name="products[' + i + '][price_per_unit_rmb]" class="form-control form-control-sm pc mask-currency-rmb" data-pf="price_per_unit_rmb" value="' + (d.price_per_unit_rmb || 0) + '" style="min-width:90px"></td>' +
            '<td class="text-accent fw-700 product-total" style="min-width:90px">0</td>' +
            '<td><button type="button" class="btn btn-sm text-danger btn-del-product-sub" title="' + escapeHtml(t('remove', 'Hapus')) + '"><i class="bi bi-dash-circle"></i></button></td></tr>';
    }

    function productRowHtml(i, d) {
        d = d || {};
        var addSubTitle = escapeHtml(t('add_product_sub', 'Tambah item dalam box'));
        return '<tr class="product-row">' +
            '<td class="product-row-num-cell"><span class="product-row-num-label">' + (i + 1) + '</span></td>' +
            '<td class="product-sub-btn-cell text-center align-middle">' +
            '<button type="button" class="btn btn-outline-secondary btn-add-product-sub" title="' + addSubTitle + '" aria-label="' + addSubTitle + '"><i class="bi bi-plus" aria-hidden="true"></i></button></td>' +
            '<td><input type="text" name="products[' + i + '][product_name]" class="form-control form-control-sm" value="' + escapeHtml(d.product_name || '') + '" style="min-width:140px"></td>' +
            '<td><input type="number" name="products[' + i + '][ctns]" class="form-control form-control-sm pc" data-pf="ctns" min="0" value="' + (d.ctns || 0) + '" style="min-width:70px"></td>' +
            productPhotoCellHtml(i, d) +
            '<td><input type="text" name="products[' + i + '][material]" class="form-control form-control-sm" value="' + (d.material || '') + '" style="min-width:120px"></td>' +
            '<td><input type="number" name="products[' + i + '][pcs_per_box]" class="form-control form-control-sm pc" data-pf="pcs_per_box" min="0" value="' + (d.pcs_per_box || 0) + '" style="min-width:70px"></td>' +
            '<td><input type="number" name="products[' + i + '][gross_weight_kg]" class="form-control form-control-sm pc" data-pf="gross_weight_kg" step="0.01" min="0" value="' + (d.gross_weight_kg || 0) + '" style="min-width:80px"></td>' +
            dimensionFieldHtml(i, 'length', 'length_cm', d) +
            dimensionFieldHtml(i, 'width', 'width_cm', d) +
            dimensionFieldHtml(i, 'height', 'height_cm', d) +
            '<td class="text-accent fw-700 product-cbm" style="min-width:70px">0</td>' +
            '<td><input type="text" name="products[' + i + '][price_per_unit_rmb]" class="form-control form-control-sm pc mask-currency-rmb" data-pf="price_per_unit_rmb" value="' + (d.price_per_unit_rmb || 0) + '" style="min-width:90px"></td>' +
            '<td class="text-accent fw-700 product-total" style="min-width:90px">0</td>' +
            '<td><button type="button" class="btn btn-sm text-danger btn-del-product"><i class="bi bi-dash-circle"></i></button></td></tr>';
    }

    function resetProductRows() {
        productIdx = 0;
        var $row = $(productRowHtml(productIdx++));
        $('#productBody').empty().append($row);
        applyProductLogisticsAccess($row);
    }

    function sortProductsForForm(products) {
        if (!products || !products.length) {
            return products || [];
        }

        var parents = products.filter(function (p) {
            return !(p.is_sub_item || p.parent_id);
        });
        parents.sort(function (a, b) {
            return (+a.sort_order || 0) - (+b.sort_order || 0) || (+a.id || 0) - (+b.id || 0);
        });

        var subsByParent = {};
        products.forEach(function (p) {
            if (p.is_sub_item || p.parent_id) {
                var parentId = String(p.parent_id || '');
                if (!subsByParent[parentId]) {
                    subsByParent[parentId] = [];
                }
                subsByParent[parentId].push(p);
            }
        });
        Object.keys(subsByParent).forEach(function (parentId) {
            subsByParent[parentId].sort(function (a, b) {
                return (+a.sort_order || 0) - (+b.sort_order || 0) || (+a.id || 0) - (+b.id || 0);
            });
        });

        var ordered = [];
        var used = {};
        parents.forEach(function (parent) {
            ordered.push(parent);
            if (parent.id) {
                used[parent.id] = true;
            }
            (subsByParent[String(parent.id)] || []).forEach(function (sub) {
                ordered.push(sub);
                if (sub.id) {
                    used[sub.id] = true;
                }
            });
        });
        products.forEach(function (p) {
            if (!p.id || !used[p.id]) {
                ordered.push(p);
            }
        });

        return ordered;
    }

    function fillProductRows(products) {
        productIdx = 0;
        $('#productBody').empty();
        products = sortProductsForForm(products);
        if (!products.length) {
            var $emptyRow = $(productRowHtml(productIdx++));
            $('#productBody').append($emptyRow);
            applyProductLogisticsAccess($emptyRow);
            return;
        }
        products.forEach(function (p) {
            var isSub = !!(p.is_sub_item || p.parent_id);
            var $row = isSub ? $(productSubRowHtml(productIdx++, p)) : $(productRowHtml(productIdx++, p));
            $('#productBody').append($row);
            if (!isSub) {
                storeProductPhotoMeta($row, p);
                storeDimensionProofMeta($row, p);
                var photoSrc = resolveProductPhotoUrl(p);
                if (photoSrc) {
                    syncProductPhotoUi($row, photoSrc);
                }
                setRmbFieldValue($row.find('[data-pf=price_per_unit_rmb]'), p.price_per_unit_rmb);
                calcProductRow($row);
                applyProductLogisticsAccess($row);
            } else {
                storeProductPhotoMeta($row, p);
                var subPhotoSrc = resolveProductPhotoUrl(p);
                if (subPhotoSrc) {
                    syncProductPhotoUi($row, subPhotoSrc);
                }
                setRmbFieldValue($row.find('[data-pf=price_per_unit_rmb]'), p.price_per_unit_rmb);
                calcProductRow($row);
            }
        });
        renumberProductRows();
        syncAllParentItemFields();
        if (typeof initInputMasks === 'function') {
            initInputMasks($('#productBody'));
        }
    }

    function renumberProductRows() {
        var parentNum = 0;
        var subNum = 0;
        $('#productBody tr.product-row').each(function () {
            var $tr = $(this);
            if ($tr.hasClass('product-sub-row')) {
                subNum += 1;
                $tr.find('.product-row-num-label').text(parentNum + '.' + subNum);
            } else {
                parentNum += 1;
                subNum = 0;
                $tr.find('.product-row-num-label').text(parentNum);
            }
        });
    }

    /** Keep products[0], products[1], … aligned with row order (fixes photo upload on save). */
    function renumberProductFieldNames() {
        $('#productBody tr.product-row').each(function (idx) {
            $(this).find('[name]').each(function () {
                var el = this;
                if (!el.name || el.name.indexOf('products[') !== 0) {
                    return;
                }
                el.name = el.name.replace(/products\[\d+]/, 'products[' + idx + ']');
            });
        });
    }

    function calcProductRow($r) {
        if ($r.hasClass('product-sub-row')) {
            var $parent = findProductParentRow($r);
            var ctns = +$parent.find('[data-pf=ctns]').val() || 0;
            var pcs = +$r.find('[data-pf=pcs_per_box]').val() || 0;
            var unit = getRmbInputValue($r.find('[data-pf=price_per_unit_rmb]'));
            var total = productLineRmbTotal(unit, pcs, ctns);
            $r.find('.product-total').text(formatProductRmbTotal(total));
            return;
        }

        var ctns = +$r.find('[data-pf=ctns]').val() || 0;
        var l = +$r.find('[data-pf=length_cm]').val() || 0;
        var w = +$r.find('[data-pf=width_cm]').val() || 0;
        var h = +$r.find('[data-pf=height_cm]').val() || 0;
        var pcs = +$r.find('[data-pf=pcs_per_box]').val() || 0;
        var unit = getRmbInputValue($r.find('[data-pf=price_per_unit_rmb]'));
        var cbm = calcProductLineCbm(l, w, h, ctns);
        var total = productLineRmbTotal(unit, pcs, ctns);
        $r.data('volumeCbm', cbm);
        $r.find('.product-cbm').text(
            typeof window.formatCbm === 'function' ? window.formatCbm(cbm) : cbm.toFixed(3),
        );
        $r.find('.product-total').text(formatProductRmbTotal(total));
        calcSubProductRows($r);
    }

    function normalizeFooterCbm(rawSum) {
        if (typeof window.truncateCbm === 'function') {
            return window.truncateCbm(rawSum);
        }
        if (typeof window.roundCbm === 'function') {
            return window.roundCbm(rawSum);
        }
        // Fallback: digit ke-4: 0–5 turun, 6–9 naik
        var scaled4 = Math.floor(Math.max(0, rawSum) * 10000 + 1e-9);
        var fourthDigit = scaled4 % 10;
        var thousandths = Math.trunc(scaled4 / 10);
        if (fourthDigit >= 6) {
            thousandths++;
        }
        return thousandths / 1000;
    }

    function rawProductLineCbm(length, width, height, ctns) {
        if (typeof window.rawCbmFromDimensions === 'function') {
            return window.rawCbmFromDimensions(length, width, height, ctns);
        }
        return (length * width * height * ctns) / 1e6;
    }

    function calcProductLineCbm(length, width, height, ctns) {
        if (typeof window.cbmFromDimensions === 'function') {
            return window.cbmFromDimensions(length, width, height, ctns);
        }
        return normalizeFooterCbm(rawProductLineCbm(length, width, height, ctns));
    }

    function calcProductTotals() {
        var ctns = 0, lineCbmSum = 0, gw = 0, totalRmb = 0;
        $('#productBody tr.product-row:not(.product-sub-row)').each(function () {
            var $r = $(this);
            var rowCtns = +$r.find('[data-pf=ctns]').val() || 0;
            var name = $.trim($r.find('[name$="[product_name]"]').val());
            if (!rowCtns && !name) {
                return;
            }
            ctns += rowCtns;
            var l = +$r.find('[data-pf=length_cm]').val() || 0;
            var w = +$r.find('[data-pf=width_cm]').val() || 0;
            var h = +$r.find('[data-pf=height_cm]').val() || 0;
            // Same value shown per-row: truncate each line, then sum (no separate raw-total path).
            var lineCbm = calcProductLineCbm(l, w, h, rowCtns);
            $r.data('volumeCbm', lineCbm);
            lineCbmSum += lineCbm;
            gw += (+$r.find('[data-pf=gross_weight_kg]').val() || 0) * Math.max(1, rowCtns);
            totalRmb += parseRmbDisplayText($r.find('.product-total').text());
        });
        $('#productBody tr.product-sub-row').each(function () {
            totalRmb += parseRmbDisplayText($(this).find('.product-total').text());
        });
        var cbm = normalizeFooterCbm(lineCbmSum);
        $('#totalCtns').text(ctns);
        $('#totalCbm').text(
            typeof window.formatCbm === 'function' ? window.formatCbm(cbm) : cbm.toFixed(3),
        );
        $('#totalGW').text(gw.toFixed(2));
        $('#totalRmb').text(formatProductRmbTotal(totalRmb));
        updatePricingCalc();
    }

    function previewProductPhoto($input) {
        var file = $input[0].files[0];
        var $row = $input.closest('.product-row');
        if (!file) {
            return;
        }
        $row.find('[name$="[remove_photo]"]').val('0');
        syncProductPhotoUi($row, URL.createObjectURL(file));
    }

    function showExportTerimaPdfButton(url) {
        var $btn = $('#btnExportTerimaPdf');
        if (!url || !cfg.canExportProducts) {
            $btn.addClass('d-none').attr('href', '#');
            return;
        }
        $btn.attr('href', url).removeClass('d-none');
    }

    function showExportShippingLabelButton(url) {
        var $btn = $('#btnExportShippingLabel');
        if (!url || !cfg.canExportProducts) {
            $btn.addClass('d-none').attr('href', '#');
            return;
        }
        $btn.attr('href', url).removeClass('d-none');
    }

    function buildShippingLabelPdfUrl(barangId) {
        if (!barangId) {
            return null;
        }
        var template = cfg.shippingLabelPdfUrlTemplate || '/barang/__ID__/shipping-label/pdf';
        return template.replace('__ID__', String(barangId));
    }

    function openShippingLabelTab(url) {
        if (!url || !cfg.canExportProducts) {
            return;
        }
        window.open(url, '_blank', 'noopener,noreferrer');
    }

    function showExportProductsExcelButton(url) {
        var $btn = $('#btnExportProductsExcel');
        if (!url || !cfg.canExportProducts) {
            $btn.addClass('d-none').attr('href', '#');
            return;
        }
        $btn.attr('href', url).removeClass('d-none');
    }

    function buildTerimaBarangPdfUrl(barangId) {
        if (!barangId) {
            return null;
        }
        var template = cfg.terimaBarangPdfUrlTemplate || '/barang/__ID__/terima-barang/pdf';
        return template.replace('__ID__', String(barangId));
    }

    function buildProductsExportExcelUrl(barangId) {
        if (!barangId) {
            return null;
        }
        var template = cfg.productsExportExcelUrlTemplate
            || '/barang/__ID__/products/export-excel';
        return template.replace('__ID__', String(barangId));
    }

    function calcTotals() {
        $('#productBody tr.product-row').each(function () {
            calcProductRow($(this));
        });
        calcProductTotals();
    }

    var goodReceiptObjectUrl = null;

    function revokeGoodReceiptObjectUrl() {
        if (goodReceiptObjectUrl) {
            URL.revokeObjectURL(goodReceiptObjectUrl);
            goodReceiptObjectUrl = null;
        }
    }

    function buildGoodReceiptUrlFromPath(photoPath) {
        return buildProductPhotoUrlFromPath(photoPath);
    }

    function resolveGoodReceiptPhotoUrl(d) {
        d = d || {};
        if (d.good_receipt_url && String(d.good_receipt_url).trim()) {
            return toAbsoluteAssetUrl(String(d.good_receipt_url).trim());
        }
        return buildGoodReceiptUrlFromPath(d.good_receipt_path);
    }

    function syncGoodReceiptPhotoUi(src) {
        var $upload = $('#goodReceiptUpload');
        var $preview = $upload.find('.good-receipt-preview');
        var $placeholder = $upload.find('.good-receipt-placeholder');
        var $btnRemove = $upload.find('.btn-remove-good-receipt');
        var $box = $upload.find('.good-receipt-box');
        var hasPhoto = !!src;
        var previousSrc = $preview.attr('src');

        if (previousSrc && previousSrc.indexOf('blob:') === 0 && previousSrc !== src) {
            URL.revokeObjectURL(previousSrc);
        }

        $preview.off('error.barangGoodReceipt').on('error.barangGoodReceipt', function () {
            var failedSrc = $(this).attr('src') || '';
            if (failedSrc.indexOf('blob:') === 0) {
                $(this).off('error.barangGoodReceipt');
                syncGoodReceiptPhotoUi(null);
                return;
            }
            var fallback = buildGoodReceiptUrlFromPath($upload.attr('data-good-receipt-path'))
                || ($upload.data('serverPhotoUrl') || null);
            if (fallback && fallback !== failedSrc) {
                $(this).attr('src', fallback);
                return;
            }
            $(this).off('error.barangGoodReceipt');
            if (!$upload.attr('data-good-receipt-path')) {
                syncGoodReceiptPhotoUi(null);
            }
        });

        if (hasPhoto) {
            var displaySrc = src.indexOf('blob:') === 0 ? src : (toAbsoluteAssetUrl(src) || src);
            if (src.indexOf('blob:') !== 0) {
                $upload.data('serverPhotoUrl', displaySrc);
            }
            $preview.attr('src', displaySrc).removeClass('d-none');
            $placeholder.addClass('d-none');
            $upload.addClass('has-photo');
            $btnRemove.removeClass('d-none');
            $box.attr('aria-label', t('change_photo', 'Change photo'));
        } else {
            $preview.removeAttr('src').addClass('d-none');
            $placeholder.removeClass('d-none');
            $upload.removeClass('has-photo');
            $btnRemove.addClass('d-none');
            $upload.removeAttr('data-good-receipt-path').removeData('serverPhotoUrl');
            $box.attr('aria-label', t('upload_photo', 'Upload photo'));
        }
    }

    function resetGoodReceiptPreview() {
        revokeGoodReceiptObjectUrl();
        $('#goodReceiptFileInput').val('');
        syncGoodReceiptPhotoUi(null);
        $('#barangForm').removeData('remove-good-receipt').removeData('existing-good-receipt');
    }

    function initGoodReceiptPhotoUi() {
        var $upload = $('#goodReceiptUpload');
        if (!$upload.length) {
            return;
        }

        $upload.on('click', '.good-receipt-box', function (e) {
            if ($(e.target).closest('.btn-remove-good-receipt').length) {
                return;
            }
            document.getElementById('goodReceiptFileInput').click();
        });

        $upload.on('keydown', '.good-receipt-box', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                document.getElementById('goodReceiptFileInput').click();
            }
        });

        $('#goodReceiptFileInput').on('change', function () {
            var input = this;
            var file = input.files && input.files[0];
            revokeGoodReceiptObjectUrl();
            $('#barangForm').removeData('remove-good-receipt');
            delete input.dataset.compressed;
            if (!file) {
                syncGoodReceiptPhotoUi(null);
                return;
            }
            compressGoodReceiptImageFile(file)
                .done(function (compressed) {
                    if (compressed && compressed !== file) {
                        setFileOnInput(input, compressed);
                        file = compressed;
                    }
                    input.dataset.compressed = '1';
                    goodReceiptObjectUrl = URL.createObjectURL(file);
                    syncGoodReceiptPhotoUi(goodReceiptObjectUrl);
                })
                .fail(function (err) {
                    if (err && err.message === 'good_receipt_too_large') {
                        showToast(t('good_receipt_too_large', 'Foto good receipt melebihi 10 MB.'), 'error');
                        input.value = '';
                        syncGoodReceiptPhotoUi(null);
                        return;
                    }
                    goodReceiptObjectUrl = URL.createObjectURL(file);
                    syncGoodReceiptPhotoUi(goodReceiptObjectUrl);
                });
        });

        $upload.on('click', '.btn-remove-good-receipt', function (e) {
            e.preventDefault();
            e.stopPropagation();
            revokeGoodReceiptObjectUrl();
            $('#goodReceiptFileInput').val('');
            $('#barangForm').data('remove-good-receipt', true);
            syncGoodReceiptPhotoUi(null);
        });
    }

    function resetInvoicePreview() {
        $('#invoicePreview').addClass('d-none');
        $('#invoiceFileName').text('');
        $('#invoiceFileIcon').attr('class', 'bi bi-file-earmark');
        $('#invoiceFileInput').val('');
        $('#barangForm').removeData('remove-invoice');
        if ($('#barangForm').data('existing-invoice')) {
            setOriginalPlExportButton(cfg.originalPlDownloadUrl || null);
        } else {
            setOriginalPlExportButton(null);
        }
    }

    function parseCurrency(val) {
        if (!val) return 0;
        return parseFloat(String(val).replace(/[^0-9.,\-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
    }

    function parseRmbDisplayText(val) {
        var raw = String(val || '').replace(/[^\d.,\-]/g, '');
        if (!raw || raw === '-' || raw === ',' || raw === '.') {
            return 0;
        }
        if (raw.indexOf('.') !== -1) {
            return parseFloat(raw.replace(/,/g, '')) || 0;
        }
        return parseFloat(raw.replace(/,/g, '')) || 0;
    }

    function productLineRmbTotal(unit, pcs, ctns) {
        var unitPrice = Number(unit) || 0;
        var pcsPerBox = Math.max(0, parseInt(pcs, 10) || 0);
        var boxCount = Math.max(0, parseInt(ctns, 10) || 0);
        if (unitPrice <= 0 || pcsPerBox <= 0) {
            return 0;
        }
        var qty = boxCount > 0 ? (pcsPerBox * boxCount) : pcsPerBox;
        return typeof window.roundRmb === 'function'
            ? window.roundRmb(unitPrice * qty)
            : Math.round(unitPrice * qty * 1000) / 1000;
    }

    function getRmbInputValue($input) {
        if (!$input || !$input.length) {
            return 0;
        }
        var el = $input[0];
        if (el && typeof window.getInputNumericValue === 'function') {
            var parsed = window.getInputNumericValue(el);
            if (parsed > 0) {
                return parsed;
            }
        }
        if (el && el.inputmask && typeof el.inputmask.unmaskedvalue === 'function') {
            var unmasked = el.inputmask.unmaskedvalue();
            if (unmasked !== '' && unmasked != null) {
                var fromMask = parseFloat(String(unmasked).replace(',', '.'));
                if (isFinite(fromMask)) {
                    return fromMask;
                }
            }
        }
        return parseRmbDisplayText($input.val());
    }

    function formatProductRmbTotal(value) {
        var rounded = typeof window.roundRmb === 'function'
            ? window.roundRmb(value)
            : Math.round(Number(value || 0) * 1000) / 1000;
        if (typeof window.formatRmbAmount === 'function') {
            return window.formatRmbAmount(rounded);
        }
        return Number(rounded).toFixed(3);
    }

    function parseDecimal(val) {
        if (!val) return 0;
        return parseFloat(String(val).replace(',', '.')) || 0;
    }

    function pricingMethodText(key, kg) {
        var i18n = typeof barangPricingI18n !== 'undefined' ? barangPricingI18n : {};
        var template = i18n[key] || '';
        return template ? template.replace(':kg', kg) : '';
    }

    function fmtRp(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function markingMinimumChargeCbm() {
        var raw = $('#barangForm').data('marking-minimum-charge');
        var value = parseFloat(raw);
        return isFiniteNumber(value) && value > 0 ? value : 0;
    }

    function updatePricingCalc() {
        var $f = $('#barangForm');
        var totalCbm = parseFloat($('#totalCbm').text()) || 0;
        var chargeKg = parseFloat($('#totalGW').text()) || 0;
        var priceCbm = getRmbInputValue($f.find('[name=price_per_cbm]'));
        var weightPrice = getRmbInputValue($f.find('[name=weight_price]'));
        var thresholdKgPerCbm = parseDecimal($f.find('[name=weight_threshold_kg]').val());
        var minChargeCbm = markingMinimumChargeCbm();
        var total;

        var billableCbmForCap = minChargeCbm > 0 ? Math.max(totalCbm, minChargeCbm) : totalCbm;
        var allowedWeightKg = thresholdKgPerCbm > 0 ? (billableCbmForCap * thresholdKgPerCbm) : 0;

        if (allowedWeightKg > 0 && chargeKg > allowedWeightKg && weightPrice > 0 && priceCbm > 0) {
            var excessKg = chargeKg - allowedWeightKg;
            var cbmPortion = billableCbmForCap * priceCbm;
            var excessCharge = excessKg * weightPrice;
            $('#calcMethodLine').removeClass('d-none').text(
                pricingMethodText('method_split', thresholdKgPerCbm.toFixed(2))
            );
            var fmtCbmSplit = typeof window.formatCbm === 'function'
                ? window.formatCbm
                : function (v) { return Number(v).toFixed(3); };
            var splitCbmLabel = billableCbmForCap !== totalCbm
                ? fmtCbmSplit(billableCbmForCap) + ' (min ' + minChargeCbm.toFixed(2) + ')'
                : fmtCbmSplit(totalCbm);
            $('#calcCbmLine').removeClass('d-none').html(
                'CBM (' + splitCbmLabel + ') &times; ' + fmtRp(priceCbm) +
                ' = <span class="text-accent fw-700">' + fmtRp(cbmPortion) + '</span>'
            );
            $('#calcWeightLine').removeClass('d-none').html(
                'Lebih berat ' + excessKg.toFixed(2) + ' kg (max ' + allowedWeightKg.toFixed(2) + ' kg) &times; ' + fmtRp(weightPrice) +
                ' = <span class="text-accent fw-700">' + fmtRp(excessCharge) + '</span>'
            );
            total = cbmPortion + excessCharge;
        } else if (allowedWeightKg > 0 && chargeKg > allowedWeightKg && weightPrice > 0) {
            var weightCharge = chargeKg * weightPrice;
            $('#calcMethodLine').removeClass('d-none').text(
                pricingMethodText('method_weight', thresholdKgPerCbm.toFixed(2))
            );
            $('#calcCbmLine').addClass('d-none');
            $('#calcWeightLine').removeClass('d-none').html(
                'Berat: ' + chargeKg.toFixed(2) + ' kg &times; ' + fmtRp(weightPrice) +
                ' = <span class="text-accent fw-700">' + fmtRp(weightCharge) + '</span>'
            );
            total = weightCharge;
        } else {
            var billableCbm = minChargeCbm > 0 ? Math.max(totalCbm, minChargeCbm) : totalCbm;
            var cbmCharge = billableCbm * priceCbm;
            var methodText = thresholdKgPerCbm > 0
                ? pricingMethodText('method_cbm', thresholdKgPerCbm.toFixed(2))
                : (minChargeCbm > 0
                    ? pricingMethodText('method_cbm_min', minChargeCbm.toFixed(2))
                    : pricingMethodText('method_cbm_default', '0'));
            $('#calcMethodLine').removeClass('d-none').text(methodText);
            var fmtCbm = typeof window.formatCbm === 'function'
                ? window.formatCbm
                : function (v) { return Number(v).toFixed(3); };
            var cbmLabel = billableCbm !== totalCbm
                ? fmtCbm(billableCbm) + ' (min ' + minChargeCbm.toFixed(2) + ')'
                : fmtCbm(totalCbm);
            $('#calcCbmLine').removeClass('d-none').html(
                'CBM: ' + cbmLabel + ' &times; ' + fmtRp(priceCbm) +
                ' = <span class="text-accent fw-700">' + fmtRp(cbmCharge) + '</span>'
            );
            $('#calcWeightLine').addClass('d-none');
            total = cbmCharge;
        }

        $('#calcTotalLine').html('Total Estimasi: <span class="text-accent">' + fmtRp(total) + '</span>');
    }

    function coerceMarkingVolumePrice(value) {
        var amount = Number(value) || 0;
        if (amount > 25000000) {
            var scaled = amount / 100;
            if (scaled >= 500000 && scaled <= 25000000) {
                console.warn('Barang form: corrected inflated CBM price', amount, '->', scaled);
                return scaled;
            }
        }
        return amount;
    }

    function coerceMarkingWeightPrice(value) {
        var amount = Number(value) || 0;
        if (amount > 500000) {
            var scaled = amount / 100;
            if (scaled >= 1000 && scaled <= 500000) {
                console.warn('Barang form: corrected inflated weight price', amount, '->', scaled);
                return scaled;
            }
        }
        return amount;
    }

    function hydrateSnapshotPricingFields(data) {
        if (!data) {
            return;
        }
        setMarkingPriceField($pricePerCbmInput(), coerceMarkingVolumePrice(data.price_per_cbm));
        setMarkingPriceField($weightPriceInput(), coerceMarkingWeightPrice(data.weight_price));
    }

    function setCurrencyFieldValue($input, value) {
        if (!$input.length) {
            return;
        }
        var amount = Number(value) || 0;
        var el = $input[0];
        if (el && el.inputmask && typeof el.inputmask.setValue === 'function') {
            el.inputmask.setValue(String(Math.round(amount)));
            $input.trigger('input');
            return;
        }
        $input.val(amount > 0 ? fmtRp(amount) : 0);
    }

    function setRmbFieldValue($input, value) {
        setCurrencyFieldValue($input, value);
    }

    function normalizePricingMatchKey(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function $cbmTypeSelect() {
        return $('#barangForm').find('[name=cbm_type]');
    }

    function dedupeMarkingPricings(pricings) {
        var seen = {};
        return (pricings || []).filter(function (row) {
            var id = row.pricing_item_id;
            if (id) {
                if (seen[id]) {
                    return false;
                }
                seen[id] = true;
            }
            return true;
        });
    }

    function populateCbmTypeOptions(pricings, selectedValue) {
        var $select = $cbmTypeSelect();
        if (!$select.length) {
            return;
        }

        var selected = normalizePricingMatchKey(selectedValue || $select.val());
        var rows = dedupeMarkingPricings(pricings);
        var placeholder = $select.data('placeholder') || t('placeholder_item_type', 'Pilih tipe item');

        if ($select.hasClass('select2-hidden-accessible') && typeof $select.select2 === 'function') {
            try {
                $select.select2('destroy');
            } catch (err) {
                $select.removeClass('select2-hidden-accessible');
            }
        }

        $select.empty();
        $select.append($('<option value=""></option>'));

        rows.forEach(function (row) {
            var name = String(row.pricing_item_name || '').trim();
            var value = row.pricing_item_id != null
                ? String(row.pricing_item_id)
                : normalizePricingMatchKey(name);
            if (!value) {
                return;
            }
            var $opt = $('<option></option>').val(value).text(name);
            if (value === selected || normalizePricingMatchKey(name) === selected) {
                $opt.prop('selected', true);
            }
            $select.append($opt);
        });

        if (!$select.val() && rows.length === 1) {
            var only = rows[0];
            $select.val(
                only.pricing_item_id != null
                    ? String(only.pricing_item_id)
                    : normalizePricingMatchKey(only.pricing_item_name || '')
            );
        }

        if (typeof window.initSelect2 === 'function') {
            window.initSelect2($select, { placeholder: placeholder });
        }

        $select.trigger('change.select2');
    }

    function clearCbmTypeOptions() {
        populateCbmTypeOptions([], '');
    }

    function getCachedMarkingPricings() {
        return $('#barangForm').data('marking-pricings') || [];
    }

    function applyItemPricingFromSelection() {
        var pricings = getCachedMarkingPricings();
        if (pricings.length) {
            applyMarkingPricingRow(pickMarkingPricingRow(pricings));
            return;
        }
        applyMarkingPricingFromSelection($('#markingSelect').find(':selected'), { preserveCbmType: true });
    }

    var legacyCbmTypeAliases = {
        cbm: 'umum',
        piece: 'umum',
        rim: 'umum',
        ton: 'umum',
        set: 'umum'
    };

    function resolveCbmTypeKey(raw) {
        var key = normalizePricingMatchKey(raw);
        return legacyCbmTypeAliases[key] || key;
    }

    function pickMarkingPricingRow(pricings) {
        if (!pricings || !pricings.length) {
            return null;
        }
        var rawType = String($('#barangForm').find('[name=cbm_type]').val() || '').trim();
        if (rawType) {
            if (/^\d+$/.test(rawType)) {
                var byId = arrayFind(pricings, function (row) {
                    return row.pricing_item_id != null && String(row.pricing_item_id) === rawType;
                });
                if (byId) {
                    return byId;
                }
            }
            var cbmType = resolveCbmTypeKey(rawType);
            var exact = arrayFind(pricings, function (row) {
                return normalizePricingMatchKey(row.pricing_item_name) === cbmType;
            });
            if (exact) {
                return exact;
            }
            var partial = arrayFind(pricings, function (row) {
                var name = normalizePricingMatchKey(row.pricing_item_name);
                return name && (name.indexOf(cbmType) !== -1 || cbmType.indexOf(name) !== -1);
            });
            if (partial) {
                return partial;
            }
            return null;
        }
        var lartas = arrayFind(pricings, function (row) {
            return normalizePricingMatchKey(row.pricing_item_name).indexOf('lartas') !== -1;
        });
        if (lartas) {
            return lartas;
        }
        var umum = arrayFind(pricings, function (row) {
            return normalizePricingMatchKey(row.pricing_item_name).indexOf('umum') !== -1;
        });
        if (umum) {
            return umum;
        }
        return pricings[0];
    }

    function $pricePerCbmInput() {
        return $('#barangForm').find('[name=price_per_cbm]');
    }

    function $weightPriceInput() {
        return $('#barangForm').find('[name=weight_price]');
    }

    function ensureMarkingPriceCurrencyMask($input) {
        if (!$input.length) {
            return;
        }
        if ($input.hasClass('mask-currency') && !$input.inputmask && typeof initInputMasks === 'function') {
            initInputMasks($input.closest('#barangForm'));
        }
    }

    function setMarkingPriceField($input, amount) {
        if (!$input.length) {
            return;
        }

        $input.prop('readonly', true);

        var value = Number(amount);
        if (!value || value <= 0) {
            if ($input.inputmask) {
                $input.inputmask('remove');
            }
            $input
                .removeClass('mask-currency')
                .addClass('barang-marking-price-unset')
                .val(t('price_cbm_not_set', 'Belum set'));
            return;
        }

        $input.removeClass('barang-marking-price-unset');
        if (!$input.hasClass('mask-currency')) {
            $input.addClass('mask-currency');
        }
        ensureMarkingPriceCurrencyMask($input);
        setCurrencyFieldValue($input, value);
    }

    function setPricePerCbmFromMarking(volumePrice) {
        setMarkingPriceField($pricePerCbmInput(), coerceMarkingVolumePrice(volumePrice));
        updatePricingCalc();
    }

    function setWeightPriceFromMarking(gwPrice) {
        setMarkingPriceField($weightPriceInput(), coerceMarkingWeightPrice(gwPrice));
        updatePricingCalc();
    }

    function clearMarkingPricingFromSelection() {
        setPricePerCbmFromMarking(0);
        setWeightPriceFromMarking(0);
        updateMarkingItemNameDisplay(null);
    }

    function canViewHargaModal() {
        return !!cfg.canViewHargaModal;
    }

    function canEditHargaModal() {
        return !!cfg.canEditHargaModal;
    }

    function $costPricePerCbmInput() {
        return $('#costPricePerCbmInput');
    }

    function $costWeightPriceInput() {
        return $('#costWeightPriceInput');
    }

    function pickCompanyItemModalRates(cbmTypeRaw) {
        var rows = cfg.companyItemModalRates || [];
        if (!rows.length) {
            return null;
        }
        var rawType = String(cbmTypeRaw || $('#barangForm').find('[name=cbm_type]').val() || '').trim();
        if (!rawType) {
            return null;
        }
        if (/^\d+$/.test(rawType)) {
            var byId = arrayFind(rows, function (row) {
                return row.pricing_item_id != null && String(row.pricing_item_id) === rawType;
            });
            if (byId) {
                return byId;
            }
        }
        var cbmType = resolveCbmTypeKey(rawType);
        var exact = arrayFind(rows, function (row) {
            return normalizePricingMatchKey(row.pricing_item_name) === cbmType;
        });
        if (exact) {
            return exact;
        }
        return arrayFind(rows, function (row) {
            var name = normalizePricingMatchKey(row.pricing_item_name);
            return name && (name.indexOf(cbmType) !== -1 || cbmType.indexOf(name) !== -1);
        }) || null;
    }

    function markingCostRatesFromRow() {
        var itemRow = pickCompanyItemModalRates();
        var rates = itemRow
            ? { volume_price: Number(itemRow.volume_price) || 0, gw_price: Number(itemRow.gw_price) || 0 }
            : { volume_price: 0, gw_price: 0 };
        var costCbm = Number(rates.volume_price) || 0;
        var costGw = Number(rates.gw_price) || 0;
        if (costCbm > 0) {
            return { volume: costCbm, gw: costGw };
        }
        if (costGw > 0) {
            return { volume: 0, gw: costGw };
        }
        return { volume: 0, gw: 0 };
    }

    function setCostPriceFromMarking() {
        if (!canViewHargaModal()) {
            return;
        }
        var $cbm = $costPricePerCbmInput();
        var $gw = $costWeightPriceInput();
        if (!$cbm.length) {
            return;
        }
        var rates = markingCostRatesFromRow();
        setCurrencyFieldValue($cbm, rates.volume);
        setCurrencyFieldValue($gw, rates.gw);
    }

    function clearMarkingCostFromSelection() {
        setCostPriceFromMarking();
    }

    function getEmbeddedMarkingPricingBundle(markingId) {
        var index = cfg.markingPricingsIndex || {};
        var key = String(markingId);
        return index[key] || index[markingId] || null;
    }

    function resolveMarkingPricingsUrl(markingId) {
        var template = cfg.markingPricingsUrlTemplate || '/marking/__ID__/pricings';
        return String(template).replace('__ID__', String(markingId));
    }

    function applyMarkingPricingResponse($f, res, options) {
        options = options || {};
        var pricings = dedupeMarkingPricings(res.pricings || []);
        $f.data('marking-pricings', pricings);
        $f.data('marking-minimum-charge', res.minimum_charge != null ? res.minimum_charge : 0);

        if (!options.preserveThreshold && res.max_weight_kg != null && res.max_weight_kg !== '') {
            var threshold = parseFloat(res.max_weight_kg);
            $f.find('[name=weight_threshold_kg]').val(
                isFiniteNumber(threshold) ? roundThresholdDownAtFive(threshold) : res.max_weight_kg
            );
        }

        var selectedType = options.preserveCbmType !== false ? $cbmTypeSelect().val() : '';
        populateCbmTypeOptions(pricings, selectedType);

        if (!$cbmTypeSelect().val() && pricings.length) {
            var fallback = pickMarkingPricingRow(pricings);
            if (fallback) {
                var fallbackVal = fallback.pricing_item_id != null
                    ? String(fallback.pricing_item_id)
                    : normalizePricingMatchKey(fallback.pricing_item_name);
                if (fallbackVal) {
                    $cbmTypeSelect().val(fallbackVal).trigger('change').trigger('change.select2');
                }
            }
        }

        if (options.preserveSnapshotPrices) {
            updateMarkingItemNameDisplay((pickMarkingPricingRow(pricings) || {}).item_name);
            updatePricingCalc();
            return;
        }

        applyMarkingPricingRow(pickMarkingPricingRow(pricings));
    }

    function fetchMarkingPricingBundle(markingId) {
        var deferred = $.Deferred();
        var embedded = getEmbeddedMarkingPricingBundle(markingId);
        if (embedded) {
            window.setTimeout(function () {
                deferred.resolve(embedded);
            }, 0);
            return deferred.promise();
        }

        $.ajax({
            url: resolveMarkingPricingsUrl(markingId),
            type: 'GET',
            dataType: 'json',
            cache: false,
            headers: { Accept: 'application/json' },
        })
            .done(function (res) {
                deferred.resolve(res || {});
            })
            .fail(function (xhr) {
                deferred.reject(xhr);
            });

        return deferred.promise();
    }

    function markingPricingLoadErrorMessage(xhr) {
        var status = xhr && xhr.status ? xhr.status : 0;
        if (status === 403) {
            return t('marking_pricing_forbidden', 'Anda tidak punya akses memuat harga marking ini.');
        }
        if (status === 401 || status === 419) {
            return t('marking_pricing_session', 'Sesi login habis. Silakan login ulang.');
        }
        if (status === 0) {
            return t('marking_pricing_network', 'Koneksi gagal. Cek internet atau coba browser lain.');
        }
        return t('marking_pricing_load_failed', 'Gagal memuat harga item marking. Muat ulang halaman atau ganti marking.');
    }

    function applyMarkingPricingRow(row) {
        if (!row) {
            clearMarkingPricingFromSelection();
            clearMarkingCostFromSelection();
            updateMarkingItemNameDisplay(null);
            return;
        }
        setPricePerCbmFromMarking(row.volume_price);
        setWeightPriceFromMarking(row.gw_price);
        setCostPriceFromMarking();
        updateMarkingItemNameDisplay(row.item_name);
    }

    function updateMarkingItemNameDisplay(name) {
        var $box = $('#markingItemNameDisplay');
        var $empty = $('#markingItemNameEmpty');
        if (!$box.length) {
            return;
        }

        var lines = String(name || '')
            .split(/\r\n|\r|\n/)
            .map(function (part) { return String(part || '').trim(); })
            .filter(function (part) { return part !== ''; });

        var seen = {};
        lines = lines.filter(function (part) {
            var key = part.toLowerCase();
            if (seen[key]) {
                return false;
            }
            seen[key] = true;
            return true;
        });

        var $value = $box.find('[data-item-name]');
        $value.empty();
        if (!lines.length) {
            $box.attr('hidden', true);
            if ($empty.length) {
                $empty.removeAttr('hidden');
            }
            return;
        }

        lines.forEach(function (line) {
            $value.append($('<div></div>').text(line));
        });
        $box.removeAttr('hidden');
        if ($empty.length) {
            $empty.attr('hidden', true);
        }
    }

    function applyMarkingPricingFromSelection($opt, options) {
        options = options || {};
        var $f = $('#barangForm');

        if (!$opt || !$opt.length) {
            $f.removeData('marking-pricings');
            $f.removeData('marking-minimum-charge');
            clearCbmTypeOptions();
            clearMarkingPricingFromSelection();
            return;
        }

        var markingId = getOptionDataAttr($opt, 'data-marking-id');
        if (!markingId) {
            $f.removeData('marking-pricings');
            $f.removeData('marking-minimum-charge');
            clearCbmTypeOptions();
            clearMarkingPricingFromSelection();
            return;
        }

        var preserveCbmType = options.preserveCbmType !== false;

        fetchMarkingPricingBundle(markingId)
            .done(function (res) {
                applyMarkingPricingResponse($f, res, {
                    preserveCbmType: preserveCbmType,
                    preserveThreshold: options.preserveThreshold,
                    preserveSnapshotPrices: options.preserveSnapshotPrices,
                });
            })
            .fail(function (xhr) {
                console.warn('Gagal memuat pricing marking.', xhr);
                notifikasi(
                    'error',
                    t('generic_failed', 'Gagal'),
                    markingPricingLoadErrorMessage(xhr),
                );
                $f.removeData('marking-pricings');
                $f.removeData('marking-minimum-charge');
                if (!options.preserveSnapshotPrices) {
                    clearCbmTypeOptions();
                    clearMarkingPricingFromSelection();
                }
            });
    }

    function syncMarkingPricingOnLoad() {
        if ($('#barangForm').data('suppress-pricing-fetch')) {
            return;
        }
        var $opt = $('#markingSelect').find(':selected');
        if (!$opt.length || !getOptionDataAttr($opt, 'data-marking-id')) {
            return;
        }
        if (isEditingBarang()) {
            applyMarkingPricingFromSelection($opt, {
                preserveCbmType: true,
                preserveThreshold: true,
                preserveSnapshotPrices: true,
            });
            return;
        }
        applyMarkingPricingFromSelection($opt, {
            preserveCbmType: true,
            preserveThreshold: true,
        });
    }

    function addResiFromInput() {
        var val = $.trim($('#resiInput').val());
        if (!val) return;
        if (resiList.indexOf(val) === -1) {
            resiList.push(val);
            renderResiTags();
        }
        $('#resiInput').val('').focus();
    }

    function normalizeResiList(input) {
        if (Array.isArray(input)) {
            return input.map(function (r) { return $.trim(String(r)); }).filter(Boolean);
        }
        if (typeof input === 'string' && $.trim(input)) {
            try {
                var parsed = JSON.parse(input);
                if (Array.isArray(parsed)) {
                    return parsed.map(function (r) { return $.trim(String(r)); }).filter(Boolean);
                }
            } catch (e) { /* legacy single value */ }
            return [$.trim(input)];
        }
        return [];
    }

    function setResiList(arr) {
        resiList = normalizeResiList(arr);
        renderResiTags();
    }

    function renderResiTags() {
        var $wrap = $('#resiTagsWrap').empty();
        var $panel = $('#resiPanel');
        var $empty = $('#resiEmptyState');
        var hasResi = resiList.length > 0;

        $panel.toggleClass('barang-resi-panel--empty', !hasResi);
        $empty.toggleClass('d-none', hasResi);
        $wrap.toggleClass('d-none', !hasResi).toggleClass('d-flex', hasResi);

        resiList.forEach(function (r, idx) {
            $wrap.append(
                '<span class="resi-tag">' +
                    '<span>' + $('<span>').text(r).html() + '</span>' +
                    '<button type="button" class="resi-remove btn btn-link p-0 border-0" data-idx="' + idx + '" aria-label="' + t('remove', 'Remove') + '">' +
                        '<i class="bi bi-x-circle"></i>' +
                    '</button>' +
                '</span>'
            );
        });
        $('#noResiHidden').val(JSON.stringify(resiList));
    }

    function storeBarangFormFlash(message, type) {
        if (!message) {
            return;
        }
        try {
            sessionStorage.setItem('barangFormFlash', JSON.stringify({
                message: String(message),
                type: type || 'success',
            }));
        } catch (e) {
            /* ignore quota errors */
        }
    }

    function showBarangFormFlash() {
        var flash = null;
        try {
            flash = JSON.parse(sessionStorage.getItem('barangFormFlash') || 'null');
        } catch (e) {
            flash = null;
        }
        if (!flash || !flash.message) {
            return;
        }
        sessionStorage.removeItem('barangFormFlash');
        if (typeof showToast === 'function') {
            showToast(flash.message, flash.type || 'success');
            return;
        }
        if (typeof notifikasi === 'function') {
            notifikasi(flash.type === 'error' ? 'error' : 'success', t('generic_success', 'Berhasil'), flash.message);
        }
    }

    showBarangFormFlash();

    $(document).on('click', '.resi-remove', function () {
        resiList.splice($(this).data('idx'), 1);
        renderResiTags();
    });
});
