@extends('layouts.auth')
@section('title', __('auth.register'))

@section('content')
<form action="{{ route('register.post') }}" method="POST">
    @csrf
    <div class="mb-3">
        <label class="form-label">{{ __('auth.full_name') }}</label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name') }}" placeholder="Nama Lengkap" autofocus required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control @error('username') is-invalid @enderror"
               value="{{ old('username') }}" placeholder="username_anda" required>
        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label">Email <span class="text-muted-glass">(opsional)</span></label>
        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
               value="{{ old('email') }}" placeholder="nama@email.com">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label">No. Telepon</label>
        <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror"
               value="{{ old('phone') }}" placeholder="08xxxxxxxxxx">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
               placeholder="Min 8 karakter" required>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-4">
        <label class="form-label">Konfirmasi Password</label>
        <input type="password" name="password_confirmation" class="form-control" placeholder="Ulangi password" required>
    </div>
    <button type="submit" class="btn btn-accent w-100 py-2">
        <i class="bi bi-person-plus-fill me-1"></i> Daftar
    </button>
    <p class="text-center mt-3 mb-0 small text-muted-glass">
        {{ __('auth.has_account') }} <a href="{{ route('login') }}" class="text-accent text-decoration-none">{{ __('auth.sign_in') }}</a>
    </p>
</form>
@endsection
