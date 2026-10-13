@props([
    'name' => 'status',
    'selected' => '',
    'statuses' => \App\Models\Barang::SHIPMENT_STATUSES,
    'allLabel' => 'Semua Status',
    'class' => 'form-select select2',
])

<select name="{{ $name }}" class="{{ $class }}" data-placeholder="{{ $allLabel }}">
    <option value="">{{ $allLabel }}</option>
    @foreach($statuses as $s)
    <option value="{{ $s }}" @selected($selected === $s)>{{ \App\Models\Barang::shipmentStatusLabel($s) }}</option>
    @endforeach
</select>
