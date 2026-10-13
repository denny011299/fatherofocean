@extends('layouts.app')
@section('title', __('user.title'))

@section('topbar-actions')
@can('manage user')
<button class="btn btn-accent btn-sm btnAdd">
    <i class="bi bi-person-plus me-1"></i>{{ __('user.add') }}
</button>
@endcan
@endsection

@section('content')
<input type="hidden" id="authUserId" value="{{ auth()->id() }}">

<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
            <i class="bi bi-people me-2"></i>{{ __('user.list_title') }}
        </h6>
    </div>

    <div class="table-responsive">
        <table id="userTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th>{{ __('user.col_name') }}</th>
                    <th>{{ __('user.col_username') }}</th>
                    <th>{{ __('user.col_email') }}</th>
                    <th>{{ __('user.col_phone') }}</th>
                    <th>{{ __('user.col_role') }}</th>
                    <th class="text-center">{{ __('user.col_commission') }}</th>
                    <th>{{ __('user.col_joined') }}</th>
                    <th class="text-center" style="min-width:148px">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="formModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="formModalTitle"><i class="bi bi-person-plus me-2 text-accent"></i>{{ __('user.add') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="dataForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">{{ __('user.label_full_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="255">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('user.label_username') }} <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required maxlength="50" placeholder="{{ __('user.placeholder_username') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('user.label_role') }} <span class="text-danger">*</span></label>
                            <select name="role" class="form-select select2" id="selRole" required data-placeholder="{{ __('user.placeholder_role') }}">
                                <option value="">{{ __('user.placeholder_role') }}</option>
                                @foreach($roles as $r)
                                <option value="{{ $r }}">{{ ucfirst(str_replace('_', ' ', $r)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('user.label_email') }}</label>
                            <input type="email" name="email" class="form-control" placeholder="{{ __('user.placeholder_optional') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('user.label_phone') }}</label>
                            <input type="text" name="phone" class="form-control" maxlength="20" placeholder="{{ __('user.placeholder_optional') }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="passwordLabel">{{ __('user.label_password') }} <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="6" placeholder="{{ __('user.placeholder_password') }}">
                    </div>
                    <div class="mb-0 d-none" id="commissionRateGroup">
                        <label class="form-label" for="commissionRateOverride">{{ __('user.label_commission_rate') }}</label>
                        <div class="input-group">
                            <input type="number" name="commission_rate_override" id="commissionRateOverride"
                                class="form-control" min="0" max="100" step="0.01"
                                placeholder="{{ __('user.placeholder_commission_general') }}">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text" id="commissionRateHelp">
                            {{ __('user.commission_rate_help', [
                                'rate' => number_format($commissionDefaults['default_rate'], 1, ',', '.'),
                            ]) }}
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    @can('manage user')
                    <button type="submit" class="btn btn-accent" id="btnSave">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="btnSpinner"></span>{{ __('common.save') }}
                    </button>
                    @endcan
                </div>
            </form>
        </div>
    </div>
</div>

@can('manage user')
<div class="modal fade" id="subordinatesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h6 class="modal-title fw-700 mb-1" id="subordinatesModalTitle">
                        <i class="bi bi-diagram-3 me-2 text-accent"></i>{{ __('user.subordinates_title') }}
                    </h6>
                    <p class="small mb-0 text-muted" id="subordinatesModalSubtitle"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="subordinatesForm">
                <div class="modal-body pt-2">
                    <div class="subordinates-info-callout mb-3">
                        <i class="bi bi-info-circle text-accent"></i>
                        <span>{{ __('user.subordinates_hint') }}</span>
                    </div>
                    <label class="form-label d-flex align-items-center justify-content-between gap-2 mb-2" for="selSubordinates">
                        <span>{{ __('user.subordinates_select') }}</span>
                        <span class="badge rounded-pill bg-accent d-none" id="subordinatesSelectedBadge">0</span>
                    </label>
                    <div class="subordinates-select-wrap position-relative" id="subordinatesSelectWrap">
                        <div class="subordinates-select-loading d-none" id="subordinatesSelectLoading">
                            <span class="spinner-border spinner-border-sm text-accent"></span>
                            <span class="small text-muted ms-2">{{ __('common.loading') }}</span>
                        </div>
                        <select id="selSubordinates" name="subordinate_ids[]" class="js-subordinates-select" multiple
                            data-placeholder="{{ __('user.subordinates_placeholder') }}">
                            <option value=""></option>
                        </select>
                    </div>
                    <p class="form-text mb-0 mt-2">{{ __('user.subordinates_multi_hint') }}</p>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex flex-wrap gap-2 justify-content-between">
                    <button type="button" class="btn btn-glass text-danger d-none" id="btnClearSubordinatesInModal">
                        <i class="bi bi-x-circle me-1"></i>{{ __('user.subordinates_clear_all') }}
                    </button>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                        <button type="submit" class="btn btn-accent" id="btnSaveSubordinates">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="subordinatesSpinner"></span>
                            <i class="bi bi-check2 me-1"></i>{{ __('common.save') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="subordinatesViewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h6 class="modal-title fw-700 mb-1">
                        <i class="bi bi-people me-2 text-accent"></i>{{ __('user.subordinates_view_title') }}
                    </h6>
                    <p class="small mb-0 text-muted" id="subordinatesViewSubtitle"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="text-center py-4 d-none" id="subordinatesViewLoading">
                    <span class="spinner-border spinner-border-sm text-accent"></span>
                    <p class="small text-muted mt-2 mb-0">{{ __('common.loading') }}</p>
                </div>
                <ul class="list-group list-group-flush subordinates-view-list d-none" id="subordinatesViewList"></ul>
                <p class="small text-muted mb-0 d-none" id="subordinatesViewEmpty">{{ __('user.subordinates_empty_list') }}</p>
            </div>
            <div class="modal-footer border-0 pt-0 d-flex flex-wrap gap-2 justify-content-between">
                <button type="button" class="btn btn-glass text-danger d-none" id="btnClearSubordinatesFromView">
                    <i class="bi bi-x-circle me-1"></i>{{ __('user.subordinates_clear_all') }}
                </button>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.close') }}</button>
                    <button type="button" class="btn btn-accent" id="btnManageFromView">
                        <i class="bi bi-pencil-square me-1"></i>{{ __('user.subordinates_manage') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
@php
    $userI18n = \App\Support\JsTranslations::merge(['user', 'swal', 'common']);
@endphp
<script>
    var token = "{{ csrf_token() }}";
    var _canManageUser = @json(auth()->user()->can('manage user'));
    Object.assign(window.__t || {}, @json($userI18n));
    window._userCommissionDefaults = @json($commissionDefaults);
    window._subordinatesTableReady = @json(\App\Models\User::subordinatesTableReady());
</script>
<script src="{{ asset('Custom_js/User/Index.js') }}?v={{ time() }}" defer></script>
@endpush
