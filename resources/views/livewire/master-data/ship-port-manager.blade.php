@section('title', 'Master Data')

<section class="row g-3">
    <div class="col-lg-6">
        <div class="glass p-4">
            <h6 class="fw-700 text-white mb-3"><i class="bi bi-ship me-2"></i>Modul Kapal</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <input wire:model.defer="vesselName" type="text" placeholder="Nama Kapal" class="form-control">
                </div>
                <div class="col-6">
                    <input wire:model.defer="vesselCode" type="text" placeholder="Kode Kapal" class="form-control">
                </div>
            </div>
            <button wire:click="createVessel" class="btn btn-accent btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Tambah Kapal
            </button>
            <div class="mt-3">
                @foreach ($vessels as $vessel)
                <div class="glass-solid p-3 mb-2 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-white fw-600">{{ $vessel->name }}</span>
                        <span class="text-muted-glass small ms-1">({{ $vessel->code }})</span>
                    </div>
                    <span class="badge-glass badge-status-{{ $vessel->status === 'active' ? 'shipped' : 'draft' }}">{{ $vessel->status }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="glass p-4">
            <h6 class="fw-700 text-white mb-3"><i class="bi bi-geo-alt me-2"></i>Modul Pelabuhan</h6>
            <div class="mb-2">
                <input wire:model.defer="portName" type="text" placeholder="Nama Pelabuhan" class="form-control">
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <input wire:model.defer="portCode" type="text" placeholder="Kode" class="form-control">
                </div>
                <div class="col-6">
                    <input wire:model.defer="portCity" type="text" placeholder="Kota" class="form-control">
                </div>
            </div>
            <button wire:click="createPort" class="btn btn-accent btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Tambah Pelabuhan
            </button>
            <div class="mt-3">
                @foreach ($ports as $port)
                <div class="glass-solid p-3 mb-2">
                    <span class="text-white fw-600">{{ $port->name }}</span>
                    <span class="text-muted-glass small ms-1">({{ $port->code }} - {{ $port->city }})</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
