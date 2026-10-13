@php
    $summaryUrl = route('notifications.summary');
    $listUrl = route('notifications.list');
    $pollUrl = route('notifications.poll');
    $readAllUrl = route('notifications.read-all');
    $inboxUrl = route('notifications.index');
@endphp
<div class="dropdown notif-bell-wrap" id="notifBellWrap">
    <button type="button"
            class="theme-toggle notif-bell-btn position-relative border-0"
            id="notifBellBtn"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-expanded="false"
            title="{{ __('notifications.inbox') }}">
        <i class="bi bi-bell-fill"></i>
        <span class="notif-badge d-none" id="notifBadge">0</span>
    </button>
    <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0 shadow-lg" id="notifDropdown">
        <div class="notif-dropdown-head d-flex align-items-center justify-content-between px-3 py-2">
            <span class="fw-700 small">{{ __('notifications.inbox') }}</span>
            <button type="button" class="btn btn-link btn-sm p-0 text-accent" id="notifMarkAllRead">
                {{ __('notifications.mark_all_read') }}
            </button>
        </div>
        <div class="notif-dropdown-body" id="notifList">
            <div class="notif-empty small text-muted-glass py-4 text-center">{{ __('notifications.empty') }}</div>
        </div>
        <div class="notif-dropdown-foot px-3 py-2 text-center border-top" style="border-color:var(--glass-border)!important">
            <a href="{{ $inboxUrl }}" class="small text-accent text-decoration-none">{{ __('notifications.view_all') }}</a>
        </div>
    </div>
</div>
<script>
    window.__notifConfig = {
        summaryUrl: @json($summaryUrl),
        listUrl: @json($listUrl),
        pollUrl: @json($pollUrl),
        readAllUrl: @json($readAllUrl),
        readUrlTemplate: @json(url('/api/notifications/__ID__/read')),
        pollIntervalMs: 25000,
    };
</script>
