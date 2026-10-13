@props([
    'label' => '',
    'cols' => 'col-md-4',
    'required' => false,
])

<div class="{{ $cols }}">
    @if($label)
    <label class="form-label">{{ $label }}@if($required) <span class="text-danger">*</span>@endif</label>
    @endif
    {{ $slot }}
</div>
