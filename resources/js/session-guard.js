/**
 * Redirect to login when session expires (401 / 419) on AJAX requests.
 */
function handleSessionExpired(xhr) {
    if (!xhr || (xhr.status !== 401 && xhr.status !== 419)) {
        return false;
    }

    const json = xhr.responseJSON || {};
    const redirect = json.redirect || '/login';
    const message = json.message || 'Sesi Anda telah berakhir. Silakan login kembali.';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'warning',
            title: 'Sesi berakhir',
            text: message,
            confirmButtonText: 'Login',
            allowOutsideClick: false,
        }).then(() => {
            window.location.href = redirect;
        });
    } else {
        window.alert(message);
        window.location.href = redirect;
    }

    return true;
}

if (typeof $ !== 'undefined') {
    $(document).ajaxError((_event, xhr) => {
        handleSessionExpired(xhr);
    });
}

window.handleSessionExpired = handleSessionExpired;
