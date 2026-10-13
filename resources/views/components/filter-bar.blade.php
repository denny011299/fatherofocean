@props(['action' => '', 'resetUrl' => ''])

<div class="glass p-3 mb-4">
    <form method="GET" action="{{ $action }}" class="row g-2 align-items-end">
        {{ $slot }}
        <div class="col-md-1">
            <button class="btn btn-accent w-100" type="submit"><i class="bi bi-search"></i></button>
        </div>
        @if($resetUrl)
        <div class="col-md-1">
            <a href="{{ $resetUrl }}" class="btn btn-glass w-100">Reset</a>
        </div>
        @endif
    </form>
</div>
