@can('edit container')
<div class="modal fade" id="containerEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-container-edit">
        <div class="modal-content">
            <form id="containerEditForm" class="modal-container-edit-form">
                <input type="hidden" name="container_id" id="editContainerId">
                <div class="modal-header">
                    <h6 class="modal-title fw-700">
                        <i class="bi bi-pencil-square me-2 text-accent"></i>
                        <span id="containerEditModalTitle">{{ __('container.edit_title') }}</span>
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_party') }}</label>
                            <input type="text" class="form-control" id="editPartai" readonly>
                        </div>
                        <div class="col-md-6">
                            @if(auth()->user()?->hasRole('super_admin'))
                            <label class="form-label">{{ __('container.label_type') }}</label>
                            <select name="type" class="form-select select2-edit" id="editContainerType" data-placeholder="{{ __('container.placeholder_select_type') }}">
                                <option value="">{{ __('container.placeholder_select_type') }}</option>
                                @foreach($containerTypes as $containerType)
                                <option value="{{ $containerType->code }}">{{ $containerType->code }} — {{ number_format((float) $containerType->volume, 2) }} CBM</option>
                                @endforeach
                            </select>
                            @else
                            <label class="form-label">{{ __('container.label_type_readonly') }}</label>
                            <input type="text" class="form-control" id="editType" readonly>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_container_no') }}</label>
                            <input type="text" name="container_no" class="form-control" maxlength="30">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_no_bl') }}</label>
                            <input type="text" name="no_bl" class="form-control" maxlength="30">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_gudang_china') }}</label>
                            <select name="gudang_china_id" class="form-select select2-edit" data-placeholder="{{ __('container.placeholder_select_china_wh') }}">
                                <option value="">{{ __('container.placeholder_select_china_wh') }}</option>
                                @foreach($gudangList as $gudang)
                                <option value="{{ $gudang->id }}">{{ $gudang->name }}{{ $gudang->address ? ' — ' . $gudang->address : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_route') }}</label>
                            <select name="route" class="form-select select2-edit" data-placeholder="{{ __('container.placeholder_select_route') }}">
                                <option value="">{{ __('container.placeholder_select_route') }}</option>
                                @foreach($routes as $route)
                                <option value="{{ $route->name }}">{{ $route->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_port') }}</label>
                            <select name="port_id" class="form-select select2-edit" data-placeholder="{{ __('container.placeholder_select_port') }}">
                                <option value="">{{ __('container.placeholder_select_port') }}</option>
                                @foreach($ports as $port)
                                <option value="{{ $port->id }}">{{ $port->name }}@if($port->city) — {{ $port->city }}@endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('container.label_department') }}</label>
                            <select name="department_item" class="form-select select2-edit" data-placeholder="{{ __('container.placeholder_select_department') }}">
                                <option value="">{{ __('container.placeholder_select_department') }}</option>
                                @foreach($departments as $dept)
                                <option value="{{ $dept->name }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_load_date') }}</label>
                            <input type="date" name="load_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_etd') }}</label>
                            <input type="date" name="etd" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_eta') }}</label>
                            <input type="date" name="eta" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_insurance_value') }}</label>
                            <input type="number" name="insurance_value" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_insurance_rate') }}</label>
                            <div class="input-group">
                                <input type="number" name="insurance_rate" class="form-control" step="0.01" min="0" max="100">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_insurance_date') }}</label>
                            <input type="date" name="insurance_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('container.label_gate_pass_date') }}</label>
                            <input type="date" name="gate_pass_date" class="form-control">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">{{ __('container.label_notes') }}</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="checkbox" name="is_join" value="1" class="form-check-input" id="editIsJoin">
                                <label class="form-check-label" for="editIsJoin">{{ __('container.join_badge') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-accent" id="btnSaveContainerEdit">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="containerEditSpinner"></span>
                        {{ __('common.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
