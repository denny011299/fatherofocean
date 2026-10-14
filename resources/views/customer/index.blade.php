@extends('layouts.app')
@section('title', __('customer.title'))

@section('topbar-actions')
@can('manage customer')
<button class="btn btn-accent btn-sm btnAdd">
    <i class="bi bi-plus-circle-fill me-1"></i>{{ __('customer.add') }}
</button>
@endcan
@endsection

@section('content')
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <div class="stat-icon-squircle squircle-royal" style="width:38px;height:38px;font-size:1.1rem;border-radius:12px;">
                <i class="bi bi-people-fill"></i>
            </div>
            <div>
                <h5 class="fw-700 mb-0" style="color:var(--text-primary)">{{ __('customer.list_title') }}</h5>
                <div style="font-size:0.75rem;color:var(--text-muted)">Kelola data akun customer dan marking kargo</div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="customerTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('customer.col_name') }}</th>
                    <th>{{ __('customer.col_username') }}</th>
                    <th>{{ __('customer.col_email') }}</th>
                    <th>{{ __('customer.col_phone') }}</th>
                    <th>{{ __('customer.col_address') }}</th>
                    <th>{{ __('customer.col_marking') }}</th>
                    <th>{{ __('customer.col_joined') }}</th>
                    <th style="width:80px">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

{{-- Modal Form --}}
<div class="modal fade" id="formModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="formModalTitle"><i class="bi bi-person-plus me-2 text-accent"></i>{{ __('customer.add') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="dataForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">{{ __('customer.label_full_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="255" placeholder="{{ __('customer.placeholder_name') }}">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('customer.label_username') }} <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required maxlength="50" placeholder="huruf, angka, - _">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" id="passwordLabel">{{ __('customer.label_password') }} <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="6" placeholder="Min. 6 karakter">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('customer.label_email') }}</label>
                            <input type="email" name="email" class="form-control" placeholder="{{ __('common.optional') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('customer.label_phone') }}</label>
                            <input type="text" name="phone" class="form-control" maxlength="20" placeholder="{{ __('common.optional') }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('customer.col_address') }}</label>
                        <textarea name="address" class="form-control" rows="2" maxlength="500" placeholder="{{ __('customer.placeholder_address') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    @can('manage customer')
                    <button type="submit" class="btn btn-accent" id="btnSave">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="btnSpinner"></span>{{ __('common.save') }}
                    </button>
                    @endcan
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $customerI18n = \App\Support\JsTranslations::merge(['customer', 'swal', 'common']);
@endphp
<script>
    var token = "{{ csrf_token() }}";
    var _canManageCustomer = @json(auth()->user()->can('manage customer'));
    Object.assign(window.__t || {}, @json($customerI18n));
</script>
<script src="{{ asset('Custom_js/Customer/Index.js') }}?v={{ time() }}" defer></script>
@endpush
