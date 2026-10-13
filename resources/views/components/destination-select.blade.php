@props([
    'name' => 'destination',
    'selected' => '',
    'class' => 'form-select select2',
    'placeholder' => 'Destinasi (Kota)',
])

<select name="{{ $name }}" class="{{ $class }}" data-placeholder="{{ $placeholder }}">
    <option value="">{{ $placeholder }}</option>
    @foreach(\App\Models\City::active()->with('province.country')->orderBy('name')->get() as $city)
    <option value="{{ $city->name }}" @selected($selected === $city->name)>{{ $city->name }} — {{ $city->province->name ?? '' }}</option>
    @endforeach
</select>
