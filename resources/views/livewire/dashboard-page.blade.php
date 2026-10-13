<section wire:poll.20s="syncStats" class="space-y-4">
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl lg:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Status Device Wablas</h2>
                <button wire:click="checkDeviceStatus" class="rounded-2xl bg-emerald-500 px-3 py-2 text-xs font-semibold text-slate-900">
                    Check Status
                </button>
            </div>
            <p class="text-sm text-slate-300">Status: <span class="font-bold text-white">{{ strtoupper($deviceStatus) }}</span></p>
            @if ($deviceStatus !== 'connected' && $qrCodeUrl)
                <img src="{{ $qrCodeUrl }}" alt="QR Code Device" class="mt-4 h-48 w-48 rounded-2xl bg-white p-2">
            @else
                <div class="mt-4 rounded-2xl border border-dashed border-white/20 p-6 text-sm text-slate-400">
                    QR Code akan tampil saat perangkat disconnected.
                </div>
            @endif
        </article>

        <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl">
            <h2 class="text-sm text-slate-300">Tracking Real-time</h2>
            <p class="mt-2 text-4xl font-bold">{{ $trackingCount }}</p>
            <p class="text-xs text-emerald-300">+12.4% hari ini</p>
        </article>

        <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl">
            <h2 class="text-sm text-slate-300">Tagihan Terakhir</h2>
            <p class="mt-2 text-2xl font-bold">Rp 12.400.000</p>
            <p class="text-xs text-amber-300">Menunggu Konfirmasi</p>
        </article>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl">
            <h2 class="text-sm text-slate-300">Kapal Aktif</h2>
            <p class="mt-2 text-3xl font-bold">{{ $vesselCount }}</p>
        </article>
        <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl">
            <h2 class="text-sm text-slate-300">Pelabuhan</h2>
            <p class="mt-2 text-3xl font-bold">{{ $portCount }}</p>
        </article>
        <article class="rounded-3xl border border-rose-400/35 bg-rose-500/10 p-5 shadow-xl backdrop-blur-xl">
            <h2 class="text-sm text-rose-100">Quick Action</h2>
            <button wire:click="$dispatch('toast', {type: 'error', message: 'Emergency shutdown triggered.'})"
                    class="mt-3 w-full animate-pulse rounded-2xl bg-rose-500 px-4 py-3 text-sm font-bold text-white">
                Emergency Shutdown
            </button>
        </article>
    </div>

    {{-- Export buttons removed: routes not yet implemented --}}
</section>
