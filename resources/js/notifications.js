/**
 * Header notification bell: badge, inbox dropdown, poll + toast for new shipment events.
 */
(function () {
    if (!window.__notifConfig) {
        return;
    }

    var cfg = window.__notifConfig;
    var latestId = 0;
    var pollTimer = null;
    var listLoaded = false;

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function csrf() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function updateBadge(count) {
        var $badge = document.getElementById('notifBadge');
        if (!$badge) {
            return;
        }
        var n = Math.max(0, parseInt(count, 10) || 0);
        $badge.textContent = n > 99 ? '99+' : String(n);
        $badge.classList.toggle('d-none', n === 0);
    }

    function applyReadStateLocally() {
        updateBadge(0);
        document.querySelectorAll('#notifList .notif-item-unread').forEach(function (el) {
            el.classList.remove('notif-item-unread');
        });
    }

    var notifToastTimer = null;

    function showNotificationToasts(items) {
        if (typeof window.showToast !== 'function' || !items || !items.length) {
            return;
        }
        if (notifToastTimer) {
            clearTimeout(notifToastTimer);
            notifToastTimer = null;
        }
        notifToastTimer = setTimeout(function () {
            notifToastTimer = null;
            var msg;
            if (items.length > 1) {
                var label = items.length + ' ' + t('new_notifications', 'notifikasi baru');
                msg = '<strong>' + esc(label) + '</strong>'
                    + '<span class="toast-sub">' + esc(items[0].title || '') + '</span>';
            } else {
                var item = items[0];
                msg = '<strong>' + esc(item.title) + '</strong>';
                if (item.body) {
                    msg += '<span class="toast-sub">' + esc(item.body) + '</span>';
                }
            }
            window.showToast(msg, 'info', { duration: 4200, maxStack: 1 });
        }, 350);
    }

    function renderList(items) {
        var $list = document.getElementById('notifList');
        if (!$list) {
            return;
        }
        if (!items || !items.length) {
            $list.innerHTML = '<div class="notif-empty small text-muted-glass py-4 text-center">'
                + esc(t('empty', 'Belum ada notifikasi.')) + '</div>';
            return;
        }
        $list.innerHTML = items.map(function (n) {
            var unread = !n.is_read ? ' notif-item-unread' : '';
            var href = n.url || '#';
            return '<a href="' + esc(href) + '" class="notif-item dropdown-item px-3 py-2' + unread + '" data-id="' + n.id + '">'
                + '<div class="d-flex gap-2 align-items-start">'
                + '<i class="bi ' + esc(n.icon || 'bi-bell') + ' mt-1 flex-shrink-0"></i>'
                + '<div class="min-w-0">'
                + '<div class="fw-600 small">' + esc(n.title) + '</div>'
                + '<div class="small text-muted-glass text-truncate-2">' + esc(n.body) + '</div>'
                + '<div class="small text-muted-glass" style="font-size:.65rem">' + esc(n.created_at_display || '') + '</div>'
                + '</div></div></a>';
        }).join('');
    }

    function loadList() {
        return $.get(cfg.listUrl, { limit: 15 }).done(function (res) {
            listLoaded = true;
            updateBadge(res.unread_count);
            renderList(res.items || []);
            if (res.items && res.items.length) {
                latestId = Math.max(latestId, res.items[0].id || 0);
            }
        });
    }

    function fetchSummary() {
        return $.get(cfg.summaryUrl).done(function (res) {
            updateBadge(res.unread_count);
            if (res.latest_id) {
                latestId = Math.max(latestId, res.latest_id);
            }
        });
    }

    function poll() {
        if (document.visibilityState === 'hidden') {
            return;
        }
        $.get(cfg.pollUrl, { since_id: latestId }).done(function (res) {
            updateBadge(res.unread_count);
            if (res.latest_id) {
                latestId = Math.max(latestId, res.latest_id);
            }
            var items = res.items || [];
            if (items.length) {
                if (listLoaded) {
                    $.get(cfg.listUrl, { limit: 15 }).done(function (r) {
                        renderList(r.items || []);
                    });
                }
                if (document.visibilityState === 'visible') {
                    showNotificationToasts(items);
                }
            }
        });
    }

    function markRead(id, callback) {
        var url = cfg.readUrlTemplate.replace('__ID__', String(id));
        $.post(url, { _token: csrf() }).done(function (res) {
            updateBadge(res.unread_count);
            if (callback) {
                callback();
            }
        });
    }

    function markAllReadServer() {
        return $.post(cfg.readAllUrl, { _token: csrf() });
    }

    function markAllRead() {
        applyReadStateLocally();
        markAllReadServer().fail(function () {
            fetchSummary().always(loadList);
        });
    }

    function openDropdown() {
        applyReadStateLocally();
        markAllReadServer();
        loadList();
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(poll, cfg.pollIntervalMs || 25000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        fetchSummary().always(startPolling);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                poll();
                startPolling();
            } else {
                stopPolling();
            }
        });

        var bellBtn = document.getElementById('notifBellBtn');
        if (bellBtn) {
            bellBtn.addEventListener('show.bs.dropdown', openDropdown);
        }

        var markAll = document.getElementById('notifMarkAllRead');
        if (markAll) {
            markAll.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                markAllRead();
            });
        }

        $(document).on('click', '#notifList .notif-item', function (e) {
            var id = $(this).data('id');
            var href = $(this).attr('href');
            if (!id) {
                return;
            }
            e.preventDefault();
            markRead(id, function () {
                if (href && href !== '#') {
                    window.location.href = href;
                }
            });
        });
    });
})();
