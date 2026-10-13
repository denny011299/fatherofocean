@props([
    'name' => 'gudang_china_id',
    'selected' => '',
    'class' => 'form-select select2',
    'placeholder' => null,
    'required' => false,
])

@php
    $placeholder = $placeholder ?? __('barang.placeholder_warehouse');
    $gudangList = \App\Models\MasterGudangChina::active()->orderBy('name')->get();
@endphp

<select name="{{ $name }}" class="{{ $class }}" data-placeholder="{{ $placeholder }}" @if($required) required @endif>
    <option value="">{{ $placeholder }}</option>
    @foreach($gudangList as $gudang)
    <option value="{{ $gudang->id }}" @selected((string) $selected === (string) $gudang->id)>
        {{ $gudang->name }}{{ $gudang->address ? ' — ' . $gudang->address : '' }}
    </option>
    @endforeach
</select>
