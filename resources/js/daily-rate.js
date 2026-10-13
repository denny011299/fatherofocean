import { getInputNumericValue, initCurrencyMask } from './utils';

function t(key, fallback) {
    return window.__t?.[key] || fallback;
}

function notify(message, type = 'success') {
    if (typeof window.Swal !== 'undefined') {
        if (type === 'error') {
            window.Swal.fire({
                icon: 'error',
                title: t('failed', 'Gagal'),
                text: message || t('daily_rate_save_failed', 'Failed to save daily rate.'),
            });
            return;
        }

        window.Swal.fire({
            toast: true,
            position: 'top-end',
            icon: type,
            title: message,
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true,
        });
        return;
    }

    window.showToast?.(message, type === 'warning' ? 'warning' : type === 'error' ? 'error' : 'success');
}

function dispatchDailyRateUpdated(payload) {
    window.__dailyRate = payload;
    document.dispatchEvent(new CustomEvent('daily-rate:updated', { detail: payload }));
}

function applyDailyRateToForms() {
    const rate = window.__dailyRate?.rate;

    if (rate == null || rate === '') {
        return;
    }

    document.querySelectorAll('.barang-daily-rate-field').forEach((input) => {
        if (input.inputmask) {
            input.inputmask.setValue(String(rate));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        input.value = rate;
    });
}

function initDailyRateHeader() {
    const header = document.getElementById('dailyRateHeader');

    if (!header || header.dataset.canManage !== '1') {
        return;
    }

    const input = document.getElementById('dailyRateInput');
    const saveBtn = document.getElementById('dailyRateSaveBtn');

    if (!input || !saveBtn || saveBtn.dataset.bound === '1') {
        return;
    }

    saveBtn.dataset.bound = '1';
    initCurrencyMask(input);

    saveBtn.addEventListener('click', async () => {
        const rate = getInputNumericValue(input);

        if (rate <= 0) {
            notify(t('daily_rate_required', 'Enter a valid rate before saving.'), 'warning');
            return;
        }

        saveBtn.disabled = true;

        try {
            const response = await fetch('/api/daily-rate', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ rate }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message =
                    data.message ||
                    (data.errors?.rate?.[0] ?? null) ||
                    t('daily_rate_save_failed', 'Failed to save daily rate.');
                throw new Error(message);
            }

            dispatchDailyRateUpdated(data.data);
            applyDailyRateToForms();

            notify(data.message || t('daily_rate_saved', "Today's rate has been updated."), 'success');
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            saveBtn.disabled = false;
        }
    });
}

function bootDailyRate() {
    initDailyRateHeader();
    applyDailyRateToForms();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDailyRate);
} else {
    bootDailyRate();
}

document.addEventListener('daily-rate:updated', () => {
    applyDailyRateToForms();
});

window.applyDailyRateToForms = applyDailyRateToForms;
