@extends('layouts.app')

@section('title', __('profile.title'))

@section('content')
<div class="row g-4">
    {{-- Section 1: Informasi Akun --}}
    <div class="col-lg-7">
        <div class="glass p-4">
            <h5 class="fw-700 mb-1" style="color: var(--text-primary); font-size: .95rem;">
                <i class="bi bi-person-circle me-2 text-accent"></i>{{ __('profile.section_info') }}
            </h5>
            <p class="mb-4" style="font-size: .78rem; color: var(--text-muted);">Perbarui data profil dan informasi kontak Anda.</p>

            <form id="formProfile">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('profile.label_name') }}</label>
                        <input type="text" name="name" class="form-control" value="{{ $user->name }}" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('profile.label_username') }}</label>
                        <input type="text" name="username" class="form-control" value="{{ $user->username }}" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('profile.label_email') }}</label>
                        <input type="email" name="email" class="form-control" value="{{ $user->email }}">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('profile.label_phone') }}</label>
                        <input type="text" name="phone" class="form-control" value="{{ $user->phone }}">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alamat</label>
                        <textarea name="address" class="form-control" rows="3">{{ $user->address }}</textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('profile.label_role') }}</label>
                        <div>
                            <span class="badge-glass text-accent">{{ $user->roles->first()?->name ?? 'user' }}</span>
                        </div>
                    </div>
                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-accent" id="btnSaveProfile">
                            <i class="bi bi-check2-circle me-1"></i> {{ __('profile.save_profile') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Section 2: Ubah Password --}}
    <div class="col-lg-5">
        <div class="glass p-4">
            <h5 class="fw-700 mb-1" style="color: var(--text-primary); font-size: .95rem;">
                <i class="bi bi-shield-lock me-2 text-accent"></i>Ubah Password
            </h5>
            <p class="mb-4" style="font-size: .78rem; color: var(--text-muted);">Pastikan menggunakan password yang kuat dan unik.</p>

            <form id="formPassword">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Password Saat Ini</label>
                        <input type="password" name="current_password" class="form-control" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Konfirmasi Password Baru</label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-accent" id="btnSavePassword">
                            <i class="bi bi-key me-1"></i> Ubah Password
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    function clearErrors(form) {
        form.find('.is-invalid').removeClass('is-invalid');
        form.find('.invalid-feedback').text('');
    }

    function showErrors(form, errors) {
        $.each(errors, function(field, messages) {
            var input = form.find('[name="' + field + '"]');
            input.addClass('is-invalid');
            input.siblings('.invalid-feedback').text(messages[0]);
        });
    }

    $('#formProfile').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var btn = $('#btnSaveProfile');
        clearErrors(form);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...');

        $.ajax({
            url: '{{ route("profile.update") }}',
            method: 'PUT',
            data: form.serialize(),
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function(res) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2500 });
            },
            error: function(xhr) {
                if (xhr.status === 422 && xhr.responseJSON?.errors) {
                    showErrors(form, xhr.responseJSON.errors);
                } else {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Terjadi kesalahan.', showConfirmButton: false, timer: 2500 });
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i> Simpan Perubahan');
            }
        });
    });

    $('#formPassword').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var btn = $('#btnSavePassword');
        clearErrors(form);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Mengubah...');

        $.ajax({
            url: '{{ route("profile.password") }}',
            method: 'PUT',
            data: form.serialize(),
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function(res) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2500 });
                form[0].reset();
            },
            error: function(xhr) {
                if (xhr.status === 422 && xhr.responseJSON?.errors) {
                    showErrors(form, xhr.responseJSON.errors);
                } else {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Terjadi kesalahan.', showConfirmButton: false, timer: 2500 });
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="bi bi-key me-1"></i> Ubah Password');
            }
        });
    });
});
</script>
@endpush
