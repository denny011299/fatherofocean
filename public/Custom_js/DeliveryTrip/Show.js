document.addEventListener('DOMContentLoaded', function () {
    if (!window.deliveryTripId) {
        return;
    }

    bindConfirmForm('#formDepart', '#btnDepart', window.deliveryTripDepartUrl);
    bindConfirmForm('#formArrive', '#btnArrive', window.deliveryTripArriveUrl);

    $('#btnCompleteTrip').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(window.deliveryTripCompleteUrl, { _token: $('meta[name="csrf-token"]').attr('content') })
            .done(function (res) {
                showToast(res.message || 'OK');
                window.location.reload();
            })
            .fail(function (xhr) {
                showToast((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal', 'error');
                $btn.prop('disabled', false);
            });
    });

    function bindConfirmForm(formSel, btnSel, url) {
        var $form = $(formSel);
        if (!$form.length) {
            return;
        }
        $form.on('submit', function (e) {
            e.preventDefault();
            var photos = $form.find('input[name="photos[]"]')[0];
            var videos = $form.find('input[name="videos[]"]')[0];
            if (!photos || !photos.files.length || !videos || !videos.files.length) {
                showToast('Foto dan video wajib diisi.', 'error');
                return;
            }

            var $btn = $(btnSel);
            var defaultBtnHtml = $btn.html();
            $btn.prop('disabled', true);
            $btn.find('.spinner-border').removeClass('d-none');

            compressAndUpload(photos.files, videos.files, url, $btn, defaultBtnHtml);
        });
    }

    function compressAndUpload(photoFiles, videoFiles, url, $btn, defaultBtnHtml) {
        var compressPromise = typeof window.compressDeliveryTripPhotos === 'function'
            ? window.compressDeliveryTripPhotos(photoFiles)
            : Promise.resolve(Array.prototype.slice.call(photoFiles));

        compressPromise
            .then(function (compressedPhotos) {
                var fd = new FormData();
                compressedPhotos.forEach(function (f) {
                    fd.append('photos[]', f);
                });
                Array.prototype.forEach.call(videoFiles, function (f) {
                    fd.append('videos[]', f);
                });

                return $.ajax({
                    url: url,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                });
            })
            .then(function (res) {
                showToast(res.message || 'OK');
                window.location.href = res.redirect || window.location.href;
            })
            .catch(function (err) {
                if (err && err.message === 'photo_too_large') {
                    showToast('Foto melebihi 10 MB. Pilih file lebih kecil.', 'error');
                } else if (err && err.responseJSON) {
                    var json = err.responseJSON;
                    showToast(json.message || (json.errors && Object.values(json.errors).flat().join('\n')) || 'Gagal', 'error');
                } else if (err && err.statusText !== 'abort') {
                    showToast('Gagal', 'error');
                }
                $btn.prop('disabled', false);
                $btn.html(defaultBtnHtml);
                $btn.find('.spinner-border').addClass('d-none');
            });
    }
});
