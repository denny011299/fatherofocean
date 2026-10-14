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
    <div class="auth-field">
        <div class="d-flex align-items-center justify-content-between mb-1.5">
            <label class="auth-label" for="loginUsername">{{ __('auth.username') }}</label>
            <span class="auth-label-badge">ID / KODE MARKING</span>
        </div>
        <div class="auth-input-group @error('username') is-invalid @enderror">
            <span class="auth-input-icon">
                <i class="bi bi-person-badge-fill"></i>
            </span>
            <input type="text" name="username" id="loginUsername"
                   class="auth-input @error('username') is-invalid @enderror"
                   value="{{ old('username') }}" placeholder="{{ __('auth.username_placeholder') }}" autofocus required>
        </div>
        @error('username')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        <div class="auth-hint"><i class="bi bi-info-circle"></i><span>{{ __('auth.login_hint_marking') }}</span></div>
    </div>

    <div class="auth-field">
        <div class="d-flex align-items-center justify-content-between mb-1.5">
            <label class="auth-label" for="loginPassword">{{ __('auth.password_label') }}</label>
            <span class="auth-label-badge">PASSCODE</span>
        </div>
        <div class="auth-input-group @error('password') is-invalid @enderror">
            <span class="auth-input-icon">
                <i class="bi bi-shield-lock-fill"></i>
            </span>
            <input type="password" name="password" id="loginPassword"
                   class="auth-input @error('password') is-invalid @enderror"
                   placeholder="{{ __('marking.placeholder_passcode') }}" required autocomplete="current-password">
            <button type="button" class="auth-password-toggle" id="loginPasswordToggle"
                    aria-label="Tampilkan password" title="Tampilkan password">
                <i class="bi bi-eye"></i>
            </button>
        </div>
        @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="form-check auth-remember">
        <input type="checkbox" name="remember" class="form-check-input" id="remember" checked>
        <label class="form-check-label" for="remember">{{ __('auth.remember_me') }}</label>
    </div>

    <button type="submit" class="btn btn-accent auth-btn-submit w-100" id="loginSubmitBtn">
        <span>Masuk ke Sistem</span>
        <i class="bi bi-arrow-right-short fs-5"></i>
    </button>

    <p class="auth-support-text">
        <i class="bi bi-headset"></i> <span>{{ __('auth.forgot_password_contact_marketing') }}</span>
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
