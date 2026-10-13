@forelse($barangs as $b)
    @include('container._partials.packing-table-row', [
        'b' => $b,
        'container' => $container,
        'canUnloadShipment' => $canUnloadShipment,
        'unloadLockedByDeparture' => $unloadLockedByDeparture,
    ])
@empty
    @include('container._partials.packing-table-empty-row', ['container' => $container])
@endforelse
