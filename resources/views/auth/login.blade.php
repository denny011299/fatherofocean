@extends('layouts.auth')
@section('title', __('auth.login'))

@section('content')
@if(session('warning'))
<div class="alert alert-warning py-2 px-3 mb-3 small" role="alert">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('warning') }}
</div>
@endif
<form action="{{ route('login.post') }}" method="POST" id="loginForm">
    @csrf
    <div class="mb-3">
        <label class="form-label">{{ __('auth.username') }}</label>
        <input type="text" name="username" class="form-control @error('username') is-invalid @enderror"
               value="{{ old('username') }}" placeholder="{{ __('auth.username_placeholder') }}" autofocus required>
        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text small text-muted-glass">{{ __('auth.login_hint_marking') }}</div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="loginPassword">{{ __('auth.password_label') }}</label>
        <div class="password-field-wrap">
            <input type="password" name="password" id="loginPassword"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="{{ __('marking.placeholder_passcode') }}" required autocomplete="current-password">
            <button type="button" class="password-toggle-btn" id="loginPasswordToggle"
                    aria-label="Tampilkan password" title="Tampilkan password">
                <i class="bi bi-eye"></i>
            </button>
        </div>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-4 form-check">
        <input type="checkbox" name="remember" class="form-check-input" id="remember" checked>
        <label class="form-check-label small text-muted-glass" for="remember">{{ __('auth.remember_me') }}</label>
    </div>
    <button type="submit" class="btn btn-accent w-100 py-2" id="loginSubmitBtn">
        <i class="bi bi-box-arrow-in-right me-1"></i> {{ __('auth.sign_in') }}
    </button>
    <p class="text-center mt-3 mb-0 small text-muted-glass">
        {{ __('auth.forgot_password_contact_marketing') }}
    </p>
</form>
<script>
(function () {
    var pageLoadedAt = Date.now();
    var sessionMaxAgeMs = 105 * 60 * 1000;
    var form = document.getElementById('loginForm');
    var submitBtn = document.getElementById('loginSubmitBtn');

    if (form) {
        form.addEventListener('submit', function (event) {
            if (Date.now() - pageLoadedAt > sessionMaxAgeMs) {
                event.preventDefault();
                window.location.reload();
            }
        });
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - pageLoadedAt > sessionMaxAgeMs) {
            window.location.reload();
        }
    });

    var input = document.getElementById('loginPassword');
    var toggle = document.getElementById('loginPasswordToggle');
    if (!input || !toggle) return;

    toggle.addEventListener('click', function () {
        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        toggle.querySelector('i').className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
        toggle.setAttribute('aria-label', visible ? 'Tampilkan password' : 'Sembunyikan password');
        toggle.setAttribute('title', visible ? 'Tampilkan password' : 'Sembunyikan password');
    });
})();
</script>
@endsection
