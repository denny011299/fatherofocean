@extends('layouts.app')
@section('title', __('notifications.title'))

@section('content')
<div class="glass p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-700 mb-0"><i class="bi bi-bell-fill me-2 text-accent"></i>{{ __('notifications.inbox') }}</h6>
        <button type="button" class="btn btn-sm btn-glass" id="inboxMarkAllRead">
            <i class="bi bi-check2-all me-1"></i>{{ __('notifications.mark_all_read') }}
        </button>
    </div>
    <div id="inboxList"></div>
    <div id="inboxEmpty" class="text-center text-muted-glass py-5 d-none">
        <i class="bi bi-inbox" style="font-size:2rem;opacity:.4"></i>
        <p class="mt-2 mb-0">{{ __('notifications.empty') }}</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var listUrl = @json(route('notifications.list'));
    var readAllUrl = @json(route('notifications.read-all'));
    var readTpl = @json(url('/api/notifications/__ID__/read'));

    function load() {
        $.get(listUrl, { limit: 50 }, function (res) {
            render(res.items || []);
        });
    }

    function render(items) {
        var $list = $('#inboxList');
        var $empty = $('#inboxEmpty');
        if (!items.length) {
            $list.empty();
            $empty.removeClass('d-none');
            return;
        }
        $empty.addClass('d-none');
        $list.html(items.map(function (n) {
            var unread = !n.is_read ? ' notif-item-unread' : '';
            return '<a href="' + (n.url || '#') + '" class="notif-item glass-solid d-block mb-2 p-3 text-decoration-none' + unread + '" data-id="' + n.id + '">' +
                '<div class="d-flex gap-2"><i class="bi ' + n.icon + ' mt-1"></i>' +
                '<div class="flex-grow-1 min-w-0">' +
                '<div class="fw-600 small">' + esc(n.title) + '</div>' +
                '<div class="small text-muted-glass">' + esc(n.body) + '</div>' +
                '<div class="small text-muted-glass mt-1">' + esc(n.created_at_display || '') + '</div>' +
                '</div></div></a>';
        }).join(''));
    }

    function esc(s) { return $('<span>').text(s || '').html(); }

    $(document).on('click', '.notif-item', function (e) {
        var id = $(this).data('id');
        var url = $(this).attr('href');
        if (id) {
            e.preventDefault();
            $.post(readTpl.replace('__ID__', id), { _token: $('meta[name="csrf-token"]').attr('content') })
                .always(function () { if (url && url !== '#') window.location.href = url; });
        }
    });

    function applyReadLocally() {
        $('#inboxList .notif-item-unread').removeClass('notif-item-unread');
    }

    $('#inboxMarkAllRead').on('click', function () {
        applyReadLocally();
        $.post(readAllUrl, { _token: $('meta[name="csrf-token"]').attr('content') })
            .fail(function () { load(); });
    });

    load();
});
</script>
@endpush
