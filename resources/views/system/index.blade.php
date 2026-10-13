@extends('layouts.app')
@section('title', 'Sistem')

@section('content')
@if(session('status'))
    <div class="alert alert-success glass-solid border-0 mb-3 py-2 px-3 small">
        <i class="bi bi-check-circle me-1"></i>{{ session('status') }}
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger glass-solid border-0 mb-3 py-2 px-3 small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        @foreach($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

@can('manage system')
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-sliders me-2 text-accent"></i>{{ __('system.general_settings') }}</h6>
            <form id="commissionRatesForm" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="companyRevenueFixed">{{ __('system.company_revenue_label') }}</label>
                <div class="input-group">
                    <span class="input-group-text">Rp</span>
                    <input type="number" class="form-control" id="companyRevenueFixed" name="company_revenue_fixed"
                        min="0" step="1000" required
                        value="{{ number_format($companyRevenueFixed, 0, '.', '') }}">
                </div>
            </div>
                <div class="col-md-4">
                    <label class="form-label" for="salesCommissionDefault">{{ __('system.commission_default_label') }}</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="salesCommissionDefault" name="default_rate"
                            min="0" max="100" step="0.01" required
                            value="{{ number_format($commissionRates['default_rate'], 2, '.', '') }}">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="invoicePaymentBank">{{ __('system.invoice_payment_bank_label') }}</label>
                    <input type="text" class="form-control" id="invoicePaymentBank" name="invoice_payment_bank"
                        maxlength="120" value="{{ $invoicePaymentSettings['bank'] }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="invoicePaymentAccountName">{{ __('system.invoice_payment_account_name_label') }}</label>
                    <input type="text" class="form-control" id="invoicePaymentAccountName" name="invoice_payment_account_name"
                        maxlength="160" value="{{ $invoicePaymentSettings['account_name'] }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="invoicePaymentAccountNumber">{{ __('system.invoice_payment_account_number_label') }}</label>
                    <input type="text" class="form-control" id="invoicePaymentAccountNumber" name="invoice_payment_account_number"
                        maxlength="120" value="{{ $invoicePaymentSettings['account_number'] }}">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-accent w-100" id="btnSaveCommissionRates">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="commissionRatesSpinner"></span>
                        <i class="bi bi-check2 me-1"></i>{{ __('system.general_settings_save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@can('view harga modal')
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-tags me-2 text-accent"></i>{{ __('system.item_modal_section_title') }}</h6>
            <form id="itemModalRatesForm">
                <div class="table-responsive mb-3">
                    <table class="table table-glass table-sm align-middle mb-0" id="itemModalRatesTable">
                        <thead>
                            <tr>
                                <th style="min-width:14rem">{{ __('system.item_modal_col_type') }}</th>
                                <th style="min-width:10rem">{{ __('system.item_modal_col_cbm') }}</th>
                                <th style="min-width:10rem">{{ __('system.item_modal_col_gw') }}</th>
                                <th style="min-width:10rem">{{ __('system.item_modal_col_company_profit') }}</th>
                                <th style="width:3rem"></th>
                            </tr>
                        </thead>
                        <tbody id="itemModalRatesBody">
                            @forelse($companyItemModalRates as $row)
                            <tr class="item-modal-row">
                                <td>
                                    <select name="pricing_item_id[]" class="form-select form-select-sm item-modal-pricing-select" @cannot('edit harga modal') disabled @endcannot>
                                        <option value="">{{ __('system.item_modal_select_type') }}</option>
                                        @foreach($pricingItems as $item)
                                            <option value="{{ $item->id }}" @selected((int) $row['pricing_item_id'] === (int) $item->id)>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" name="cost_volume_price[]" class="form-control" min="0" step="1000"
                                            value="{{ number_format($row['volume_price'], 0, '.', '') }}"
                                            @cannot('edit harga modal') readonly tabindex="-1" @endcannot>
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" name="cost_gw_price[]" class="form-control" min="0" step="1000"
                                            value="{{ number_format($row['gw_price'], 0, '.', '') }}"
                                            @cannot('edit harga modal') readonly tabindex="-1" @endcannot>
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" name="company_profit_per_shipment[]" class="form-control" min="0" step="1000"
                                            value="{{ number_format($row['company_profit_per_shipment'] ?? 0, 0, '.', '') }}"
                                            @cannot('edit harga modal') readonly tabindex="-1" @endcannot>
                                    </div>
                                </td>
                                <td class="text-end">
                                    @can('edit harga modal')
                                    <button type="button" class="btn btn-sm btn-glass item-modal-remove" title="{{ __('system.item_modal_remove') }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @can('edit harga modal')
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-glass btn-sm" id="btnAddItemModalRow">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('system.item_modal_add') }}
                    </button>
                    <button type="submit" class="btn btn-accent btn-sm" id="btnSaveItemModalRates">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="itemModalRatesSpinner"></span>
                        <i class="bi bi-check2 me-1"></i>{{ __('system.item_modal_save') }}
                    </button>
                </div>
                @endcan
            </form>
            <template id="itemModalRowTemplate">
                <tr class="item-modal-row">
                    <td>
                        <select name="pricing_item_id[]" class="form-select form-select-sm item-modal-pricing-select">
                            <option value="">{{ __('system.item_modal_select_type') }}</option>
                            @foreach($pricingItems as $item)
                                <option value="{{ $item->id }}">{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Rp</span>
                            <input type="number" name="cost_volume_price[]" class="form-control" min="0" step="1000" value="0">
                        </div>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Rp</span>
                            <input type="number" name="cost_gw_price[]" class="form-control" min="0" step="1000" value="0">
                        </div>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Rp</span>
                            <input type="number" name="company_profit_per_shipment[]" class="form-control" min="0" step="1000" value="0">
                        </div>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-glass item-modal-remove" title="{{ __('system.item_modal_remove') }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            </template>
        </div>
    </div>
</div>
@endcan

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-image me-2 text-accent"></i>Logo Aplikasi</h6>
            <p class="small mb-3" style="color:var(--text-muted)">
                Logo ditampilkan di sidebar aplikasi dan halaman lacak publik. Format PNG, JPG/JPEG, atau WebP, maks. 2 MB. Foto iPhone (HEIC) perlu dikonversi ke JPG terlebih dahulu.
            </p>

            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <div class="brand-logo-preview">
                    @if($appLogoUrl)
                        <img src="{{ $appLogoUrl }}" alt="Logo aplikasi" class="brand-mark brand-mark--preview">
                    @else
                        <div class="brand-logo-preview-fallback">
                            <i class="bi bi-tsunami"></i>
                        </div>
                    @endif
                </div>
                <div class="small" style="color:var(--text-muted)">
                    @if($appLogoUrl)
                        Logo kustom aktif.
                    @else
                        Menggunakan ikon default tsunami + teks.
                    @endif
                </div>
            </div>

            @can('manage system')
            <form method="POST" action="{{ route('system.logo.update') }}" enctype="multipart/form-data" class="mb-3">
                @csrf
                <div class="mb-3">
                    <label for="logo" class="form-label">Unggah logo baru</label>
                    <input
                        type="file"
                        name="logo"
                        id="logo"
                        class="form-control compress-image @error('logo') is-invalid @enderror"
                        accept="image/png,image/jpeg,image/jpg,image/webp,.png,.jpg,.jpeg,.webp"
                        required
                    >
                    @error('logo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-accent">
                    <i class="bi bi-upload me-1"></i>{{ __('system.save_logo') }}
                </button>
            </form>

            @if($appLogoUrl)
                <form method="POST" action="{{ route('system.logo.remove') }}" onsubmit="return confirm('Kembalikan logo ke default?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-glass">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Kembalikan Default
                    </button>
                </form>
            @endif
            @endcan
        </div>
    </div>
</div>

@can('manage system')
<div class="row g-3">
    <div class="col-lg-6">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Emergency Kill Switch</h6>
            <p class="small mb-3" style="color:var(--text-muted)">
                Shutdown akan <strong>export database</strong> terlebih dahulu (ZIP), lalu mengaktifkan maintenance mode.
            </p>

            <form id="shutdownForm">
                <div class="mb-3">
                    <label class="form-label">Secret Token</label>
                    <input type="text" name="secret" class="form-control" placeholder="Masukkan secret bypass token" required>
                    <div class="form-text" style="color:var(--text-muted);font-size:.7rem">Token ini untuk bypass maintenance mode.</div>
                </div>
                <button type="submit" class="btn btn-danger-glow w-100 py-3 fs-6" id="shutdownBtn">
                    <i class="bi bi-power me-2"></i>EMERGENCY SHUTDOWN
                </button>
                <div class="text-center mt-2 d-none" id="shutdownProgress">
                    <div class="spinner-border spinner-border-sm text-warning me-1"></div>
                    <span class="small text-warning" id="shutdownStatus">Exporting database...</span>
                </div>
            </form>

            <hr style="border-color:var(--glass-border)">

            <form id="upForm">
                <button type="submit" class="btn btn-accent w-100 py-2">
                    <i class="bi bi-play-circle me-1"></i>Bring System Online
                </button>
            </form>
        </div>

    </div>

    <div class="col-lg-6">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-database-down me-2 text-accent"></i>Backup Database</h6>
            <p class="small mb-3" style="color:var(--text-muted)">Export seluruh database ke file SQL, di-zip, dan langsung download.</p>

            <div class="glass-solid p-3 mb-3">
                <div class="d-flex justify-content-between mb-1"><span class="small" style="color:var(--text-muted)">Database</span><span class="small fw-600" style="color:var(--text-primary)">{{ config('database.connections.mysql.database') }}</span></div>
                <div class="d-flex justify-content-between"><span class="small" style="color:var(--text-muted)">Server</span><span class="small fw-600" style="color:var(--text-primary)">{{ config('database.default') }} — {{ config('database.connections.mysql.host') }}</span></div>
            </div>

            <button type="button" class="btn btn-accent w-100 py-3 fs-6" id="manualExportBtn" onclick="exportDB()">
                <i class="bi bi-download me-2"></i>BACKUP NOW
            </button>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
const csrf = '{{ csrf_token() }}';

async function fetchDatabaseExport() {
    const response = await fetch('{{ route("system.export-db") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/octet-stream, application/json',
        },
    });

    if (!response.ok) {
        const contentType = response.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
            const data = await response.json();
            throw new Error(data.message || 'Export gagal');
        }

        throw new Error('Export gagal');
    }

    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?(.+?)"?$/);
    const filename = match ? match[1] : 'database_backup.zip';
    const blob = await response.blob();

    return { blob, filename };
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function exportDB(callback) {
    const btn = document.getElementById('manualExportBtn');
    if (!btn || btn.disabled) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Exporting...';

    fetchDatabaseExport().then(({ blob, filename }) => {
        downloadBlob(blob, filename);

        btn.disabled = false;
        btn.innerHTML = origHtml;
        showToast('Database berhasil di-export.');
        if (callback) callback();
    }).catch(err => {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        showToast(err.message || 'Gagal export database.', 'error');
        console.error(err);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const itemModalRowTemplate = document.getElementById('itemModalRowTemplate');
    const itemModalRatesBody = document.getElementById('itemModalRatesBody');
    const itemModalRatesForm = document.getElementById('itemModalRatesForm');

    function addItemModalRow() {
        if (!itemModalRowTemplate || !itemModalRatesBody) {
            return;
        }
        itemModalRatesBody.appendChild(itemModalRowTemplate.content.cloneNode(true));
    }

    document.getElementById('btnAddItemModalRow')?.addEventListener('click', addItemModalRow);

    itemModalRatesBody?.addEventListener('click', function (e) {
        const btn = e.target.closest('.item-modal-remove');
        if (!btn) {
            return;
        }
        btn.closest('tr')?.remove();
    });

    if (itemModalRatesForm) {
        itemModalRatesForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveItemModalRates');
            const spinner = document.getElementById('itemModalRatesSpinner');
            const rows = [];
            itemModalRatesBody.querySelectorAll('.item-modal-row').forEach(function (tr) {
                const pricingItemId = tr.querySelector('[name="pricing_item_id[]"]')?.value;
                if (!pricingItemId) {
                    return;
                }
                rows.push({
                    pricing_item_id: pricingItemId,
                    cost_volume_price: tr.querySelector('[name="cost_volume_price[]"]')?.value ?? 0,
                    cost_gw_price: tr.querySelector('[name="cost_gw_price[]"]')?.value ?? 0,
                    company_profit_per_shipment: tr.querySelector('[name="company_profit_per_shipment[]"]')?.value ?? 0,
                });
            });
            btn.disabled = true;
            spinner.classList.remove('d-none');
            fetch(@json($systemUrls['itemModalRates'] ?? route('system.item-modal-rates.update')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ rows: rows }),
            })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                .then(function (res) {
                    if (!res.ok) throw new Error(res.json.message || 'Gagal menyimpan');
                    showToast(res.json.message || 'Tersimpan');
                })
                .catch(function (err) {
                    showToast(err.message || 'Gagal menyimpan', 'error');
                })
                .finally(function () {
                    btn.disabled = false;
                    spinner.classList.add('d-none');
                });
        });
    }

    const commissionForm = document.getElementById('commissionRatesForm');
    if (commissionForm) {
        commissionForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveCommissionRates');
            const spinner = document.getElementById('commissionRatesSpinner');
            btn.disabled = true;
            spinner.classList.remove('d-none');
            fetch(@json($systemUrls['commissionRates'] ?? route('system.commission-rates.update')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    default_rate: document.getElementById('salesCommissionDefault').value,
                    company_revenue_fixed: document.getElementById('companyRevenueFixed').value,
                    invoice_payment_bank: document.getElementById('invoicePaymentBank').value,
                    invoice_payment_account_name: document.getElementById('invoicePaymentAccountName').value,
                    invoice_payment_account_number: document.getElementById('invoicePaymentAccountNumber').value,
                }),
            })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                .then(function (res) {
                    if (!res.ok) throw new Error(res.json.message || 'Gagal menyimpan');
                    showToast(res.json.message || 'Tersimpan');
                })
                .catch(function (err) {
                    showToast(err.message || 'Gagal menyimpan', 'error');
                })
                .finally(function () {
                    btn.disabled = false;
                    spinner.classList.add('d-none');
                });
        });
    }

    $('#shutdownForm').on('submit', function(e) {
        e.preventDefault();
        if (!confirm('PERINGATAN: Sistem akan di-shutdown setelah backup database. Lanjutkan?')) return;

        const secret = $('[name=secret]', this).val();
        const btn = document.getElementById('shutdownBtn');
        const progress = document.getElementById('shutdownProgress');
        const statusText = document.getElementById('shutdownStatus');

        btn.disabled = true;
        progress.classList.remove('d-none');
        statusText.textContent = 'Exporting database...';

        // Step 1: Export DB
        fetchDatabaseExport().then(({ blob, filename }) => {
            downloadBlob(blob, filename);

            statusText.textContent = 'Backup downloaded. Shutting down...';

            // Step 2: Shutdown
            return $.ajax({
                url: '{{ route("system.shutdown") }}',
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                data: { secret: secret }
            });
        }).then(res => {
            statusText.textContent = 'Sistem dalam maintenance mode.';
            showToast(res.message || 'Sistem dalam maintenance mode.', 'warning');
            progress.classList.add('d-none');
            btn.disabled = false;
        }).catch(err => {
            statusText.textContent = 'Gagal: ' + (err.message || 'Unknown error');
            btn.disabled = false;
            console.error(err);
        });
    });

    $('#upForm').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: '{{ route("system.up") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            success(res) { showToast(res.message || 'Sistem berhasil diaktifkan kembali.'); },
            error(xhr) { showToast(xhr.responseJSON?.message || 'Gagal', 'error'); }
        });
    });
});
</script>
@endpush
