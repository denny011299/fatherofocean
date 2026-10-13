@php
    $dailyRate = \App\Support\DailyRate::current();
    $rateValue = $dailyRate['rate'] ?? '';
@endphp
<div class="daily-rate-header" id="dailyRateHeader" data-can-manage="{{ auth()->user()->can('edit rmb rate') ? '1' : '0' }}">
    <label class="daily-rate-header__label" for="dailyRateInput">{{ __('settings.daily_rate_label') }}</label>
    @can('edit rmb rate')
    <div class="daily-rate-header__controls">
        <div class="daily-rate-header__input-group">
            <input type="text"
                id="dailyRateInput"
                class="form-control form-control-sm mask-currency daily-rate-header__input"
                value="{{ $rateValue !== '' && $rateValue !== null ? number_format((float) $rateValue, 0, ',', '.') : '' }}"
                inputmode="decimal"
                autocomplete="off"
                aria-label="{{ __('settings.daily_rate_label') }}">
        </div>
        <button type="button" class="btn btn-accent btn-sm daily-rate-header__save" id="dailyRateSaveBtn" title="{{ __('settings.daily_rate_save') }}">
            <i class="bi bi-check-lg"></i>
        </button>
    </div>
    @else
    <span class="daily-rate-header__readonly" id="dailyRateReadonly">
        @if($rateValue !== '' && $rateValue !== null)
            Rp {{ number_format((float) $rateValue, 0, ',', '.') }}
        @else
            <span class="text-muted-glass">—</span>
        @endif
    </span>
    @endcan
</div>
