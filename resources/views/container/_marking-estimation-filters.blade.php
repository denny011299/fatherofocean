<div class="mb-3">
    <label class="form-label mb-1" for="estFilterDatePreset">
        <i class="bi bi-calendar-range me-1"></i>{{ __('container.filter_tanggal_masuk') }}
    </label>
    <select id="estFilterDatePreset" class="form-select form-select-sm">
        <option value="">{{ __('common.date_preset_none') }}</option>
        <option value="today">{{ __('common.date_preset_today') }}</option>
        <option value="yesterday">{{ __('common.date_preset_yesterday') }}</option>
        <option value="7d">{{ __('common.date_preset_7d') }}</option>
        <option value="30d">{{ __('common.date_preset_30d') }}</option>
        <option value="custom">{{ __('common.date_preset_custom') }}</option>
    </select>
</div>
<div id="estFilterDateCustomRow" class="row g-2 mb-3 d-none">
    <div class="col-6">
        <label class="form-label mb-1 small" for="estFilterTanggalFrom">{{ __('barang.filter_tanggal_from') }}</label>
        <input type="date" id="estFilterTanggalFrom" class="form-control form-control-sm" autocomplete="off">
    </div>
    <div class="col-6">
        <label class="form-label mb-1 small" for="estFilterTanggalTo">{{ __('barang.filter_tanggal_to') }}</label>
        <input type="date" id="estFilterTanggalTo" class="form-control form-control-sm" autocomplete="off">
    </div>
</div>
