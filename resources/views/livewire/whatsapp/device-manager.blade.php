<section wire:poll.30s="refreshStatus" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl lg:col-span-2">
        <h2 class="text-lg font-semibold">Token Management</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-sm">
                Device Name
                <input wire:model.defer="name" type="text" class="mt-1 w-full rounded-xl bg-slate-900/70 p-2">
            </label>
            <label class="text-sm">
                Wablas Token
                <input wire:model.defer="token" type="text" class="mt-1 w-full rounded-xl bg-slate-900/70 p-2">
            </label>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button wire:click="saveToken" class="rounded-2xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900">Save Token</button>
            <button wire:click="refreshStatus" class="rounded-2xl bg-white/15 px-4 py-2 text-sm">Refresh Status</button>
        </div>
    </article>

    <article class="rounded-3xl border border-white/15 bg-white/10 p-5 shadow-xl backdrop-blur-xl">
        <h2 class="text-lg font-semibold">Connection Status</h2>
        <p class="mt-2 text-2xl font-bold {{ $status === 'connected' ? 'text-emerald-300' : 'text-rose-300' }}">
            {{ strtoupper($status) }}
        </p>
        @if ($status !== 'connected' && $qrCodeUrl)
            <img src="{{ $qrCodeUrl }}" alt="Device QR" class="mt-4 rounded-2xl bg-white p-2">
        @else
            <p class="mt-4 text-sm text-slate-400">QR code akan tampil ketika device disconnected.</p>
        @endif
    </article>
</section>
