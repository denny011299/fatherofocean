document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.__bongkarMuatConfig || {};

    var sessionId = null;
    var pendingBarang = null;
    var $colyModal = $('#colyModal');
    var colyModal = $colyModal.length && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance($colyModal[0])
        : null;

    function t(key, fallback) {
        return (window.__t && window.__t[key]) || fallback || key;
    }

    function token() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function tpl(url, vars) {
        var out = url;
        Object.keys(vars).forEach(function (k) {
            out = out.replace('__' + k + '__', String(vars[k]));
        });
        return out;
    }

    function toast(msg, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(msg, type || 'success');
        }
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    var containerOptions = normalizeContainerOptions(cfg.initialContainers || []);
    var containerIndex = {};
    var containerSearchTimer = null;
    var containerRequest = null;

    rememberContainerOptions(containerOptions);
    initContainerAutocomplete(cfg);

    $('#btnLookup').on('click', lookupIndex);
    $('#scanInput').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lookupIndex();
        }
    });
    $('#modalActual').on('focus click', function () {
        $(this).select();
    });
    $('#modalActual').on('mouseup', function (e) {
        e.preventDefault();
    });
    $colyModal.on('hidden.bs.modal', function () {
        focusScanInput(200);
    });

    $colyModal.on('shown.bs.modal', function () {
        setTimeout(function () {
            var el = document.getElementById('modalActual');
            if (el) {
                el.focus({ preventScroll: true });
                el.select();
            }
        }, 150);
    });

    $('#modalActual').on('input', syncVarianceUi);
    $('#btnModalConfirm').on('click', confirmColy);
    $('#btnComplete').on('click', completeSession);

    $(document).on('click', '.bm-remove', function () {
        var scanId = $(this).data('scan-id');
        if (!sessionId || !scanId) {
            return;
        }
        $.ajax({
            url: tpl(cfg.removeTpl, { SID: sessionId, SCAN: scanId }),
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': token() },
            success: function (res) {
                toast(res.message || t('success'));
                renderSession(res.session);
            },
            error: function (xhr) {
                toast((xhr.responseJSON && xhr.responseJSON.message) || t('generic_failed'), 'error');
            },
        });
    });

    if (cfg.resumeSession && cfg.resumeSession.session) {
        sessionId = cfg.resumeSession.session.id;
        if (cfg.resumeContainerId) {
            var cid = String(cfg.resumeContainerId);
            var label = cfg.resumeSession.session.container_label
                || cfg.resumeSession.session.partai
                || cid;

            containerIndex[cid] = containerIndex[cid] || {
                id: cid,
                label: label,
                partai: cfg.resumeSession.session.partai || label,
                container_no: '',
                eta_label: '',
                eta_relative: '',
                barang_count: 0,
            };

            $('#containerSelect').val(cid);
            $('#containerAutocomplete').val(label);
        }
        renderSession(cfg.resumeSession);
        $('#scanPlaceholder').addClass('d-none');
        $('#scanWorkspace').removeClass('d-none');
        focusScanInput(200);
    }

    function initContainerAutocomplete(cfg) {
        var $input = $('#containerAutocomplete');
        if (!$input.length) {
            return;
        }

        $input.on('focus click', function () {
            openContainerList(containerOptions);
            if (!containerOptions.length) {
                fetchContainerOptions('');
            }
        });

        $input.on('input', function () {
            var keyword = $(this).val().trim();

            if (keyword === '') {
                $('#containerSelect').val('');
                resetUi();
                openContainerList(containerOptions);
            }

            window.clearTimeout(containerSearchTimer);
            containerSearchTimer = window.setTimeout(function () {
                fetchContainerOptions(keyword);
            }, 180);
        });

        $input.on('keydown', function (e) {
            if (e.key === 'Enter') {
                var $first = $('#containerAutocompleteList .bm-autocomplete-item').first();
                if ($first.length) {
                    e.preventDefault();
                    selectContainerOption($first.data('id'));
                }
                return;
            }

            if (e.key === 'Escape') {
                closeContainerList();
            }
        });

        $input.on('blur', function () {
            window.setTimeout(closeContainerList, 150);
        });

        $(document).on('mousedown', '.bm-autocomplete-item', function (e) {
            e.preventDefault();
        });

        $(document).on('click', '.bm-autocomplete-item', function () {
            selectContainerOption($(this).data('id'));
        });
    }

    function normalizeContainerOptions(rows) {
        return (rows || []).map(function (row) {
            var id = row && row.id != null ? String(row.id) : '';
            if (!id) {
                return null;
            }

            var label = row.text || row.partai || row.container_no || ('#' + id);

            return {
                id: id,
                label: label,
                partai: row.partai || label,
                container_no: row.container_no || '',
                eta_label: row.eta_label || '',
                eta_relative: row.eta_relative || '',
                barang_count: row.barang_count != null ? row.barang_count : '',
            };
        }).filter(Boolean);
    }

    function rememberContainerOptions(list) {
        (list || []).forEach(function (item) {
            containerIndex[String(item.id)] = item;
        });
    }

    function fetchContainerOptions(keyword) {
        var localMatches = filterContainerOptions(keyword);
        openContainerList(localMatches);

        if (!cfg.containersUrl) {
            return;
        }

        if (containerRequest && typeof containerRequest.abort === 'function') {
            containerRequest.abort();
        }

        containerRequest = $.get(cfg.containersUrl, { q: keyword || '' })
            .done(function (res) {
                containerOptions = normalizeContainerOptions(res.results || []);
                rememberContainerOptions(containerOptions);
                openContainerList(filterContainerOptions(keyword));
            })
            .fail(function (xhr) {
                if (xhr && xhr.statusText === 'abort') {
                    return;
                }
                toast((xhr.responseJSON && xhr.responseJSON.message) || t('containers_load_failed'), 'error');
            });
    }

    function filterContainerOptions(keyword) {
        var search = String(keyword || '').toLowerCase().trim();
        if (!search) {
            return containerOptions;
        }

        return containerOptions.filter(function (item) {
            return [
                item.label,
                item.partai,
                item.container_no,
                item.eta_label,
                item.eta_relative,
            ].join(' ').toLowerCase().indexOf(search) !== -1;
        });
    }

    function openContainerList(list) {
        var $list = $('#containerAutocompleteList');
        if (!$list.length) {
            return;
        }

        renderContainerList(list);
        $list.removeClass('d-none');
    }

    function closeContainerList() {
        $('#containerAutocompleteList').addClass('d-none');
    }

    function renderContainerList(list) {
        var $list = $('#containerAutocompleteList');
        if (!$list.length) {
            return;
        }

        if (!list || !list.length) {
            $list.html('<div class="bm-autocomplete-empty">' + esc(t('autocomplete_no_containers')) + '</div>');
            return;
        }

        var html = list.map(function (item) {
            var meta = [];
            if (item.container_no) {
                meta.push(item.container_no);
            }
            if (item.eta_label) {
                meta.push('ETA ' + item.eta_label + (item.eta_relative ? ' (' + item.eta_relative + ')' : ''));
            }
            if (item.barang_count !== '') {
                meta.push(item.barang_count + ' ' + t('items_available_short'));
            }

            return '<button type="button" class="bm-autocomplete-item" data-id="' + esc(item.id) + '">'
                + '<div class="bm-autocomplete-item-title">' + esc(item.partai || item.label) + '</div>'
                + '<div class="bm-autocomplete-item-meta">' + esc(meta.join(' · ')) + '</div>'
                + '</button>';
        }).join('');

        $list.html(html);
    }

    function selectContainerOption(id) {
        var item = containerIndex[String(id)];
        if (!item) {
            return;
        }

        $('#containerSelect').val(item.id);
        $('#containerAutocomplete').val(item.partai || item.label);
        closeContainerList();
        openSession(item.id);
    }

    function resetUi() {
        sessionId = null;
        pendingBarang = null;
        $('#sessionSummary').addClass('d-none');
        $('#scanPlaceholder').removeClass('d-none');
        $('#scanWorkspace').addClass('d-none');
        $('#scannedList, #pendingList').empty();
    }

    function openSession(containerId) {
        $('#containerSelect').val(String(containerId));
        $.post(cfg.sessionOpenUrl, {
            _token: token(),
            container_id: containerId,
        }).done(function (res) {
            sessionId = res.session.id;
            renderSession(res);
            $('#scanPlaceholder').addClass('d-none');
            $('#scanWorkspace').removeClass('d-none');
            resetScanInput();
        }).fail(function (xhr) {
            toast((xhr.responseJSON && xhr.responseJSON.message) || t('generic_failed'), 'error');
        });
    }

    function renderSession(payload) {
        if (!payload) {
            return;
        }
        var prog = payload.progress || {};
        var total = prog.total || 0;
        var scanned = prog.scanned || 0;
        var pct = total > 0 ? Math.round((scanned / total) * 100) : 0;

        $('#sessionSummary').removeClass('d-none');
        $('#progressText').text(scanned + ' / ' + total);
        $('#progressBar').css('width', pct + '%');
        $('#sessionPartaiLabel').text(payload.session.container_label || payload.session.partai || '');
        $('#scannedCount').text(scanned);
        $('#pendingCount').text(prog.pending || 0);

        var $scanned = $('#scannedList').empty();
        (payload.scans || []).forEach(function (row) {
            $scanned.append(scannedItemHtml(row));
        });
        if (!payload.scans || !payload.scans.length) {
            $scanned.html('<div class="small text-muted-glass">—</div>');
        }

        var $pending = $('#pendingList').empty();
        (payload.pending || []).forEach(function (row) {
            $pending.append(
                '<div class="bm-item">'
                + '<div class="bm-index">' + esc(row.no_index) + '</div>'
                + '<div class="small text-muted-glass">' + esc(row.marking || '') + ' · ' + esc(row.customer_name || '') + '</div>'
                + '<div class="small">Coly: <strong>' + esc(row.expected_ctns) + '</strong></div>'
                + '</div>',
            );
        });
        if (!payload.pending || !payload.pending.length) {
            $pending.html('<div class="small text-success"><i class="bi bi-check-lg"></i> ' + esc(t('all_scanned')) + '</div>');
        }
    }

    function scannedItemHtml(row) {
        var variance = row.has_variance;
        var badge = variance
            ? '<span class="badge bg-danger ms-1">' + t('coly_diff') + '</span>'
            : '<span class="badge bg-success ms-1">' + t('coly_match') + '</span>';
        var note = variance && row.variance_note
            ? '<div class="small text-danger mt-1">' + esc(row.variance_note) + '</div>'
            : '';
        return '<div class="bm-item' + (variance ? ' variance' : '') + '">'
            + '<div class="d-flex justify-content-between align-items-start gap-2">'
            + '<div><div class="bm-index">' + esc(row.no_index) + ' ' + badge + '</div>'
            + '<div class="small text-muted-glass">' + esc(row.marking || '') + '</div>'
            + '<div class="small">Coly: <s class="text-muted">' + esc(row.expected_ctns) + '</s> → <strong>' + esc(row.confirmed_ctns) + '</strong></div>'
            + note + '</div>'
            + '<button type="button" class="btn btn-sm btn-glass bm-remove" data-scan-id="' + row.id + '" title="' + esc(t('delete')) + '"><i class="bi bi-x-lg"></i></button>'
            + '</div></div>';
    }

    function lookupIndex() {
        if (!sessionId) {
            return;
        }
        var noIndex = $('#scanInput').val().trim();
        if (!noIndex) {
            focusScanInput();
            return;
        }
        $.post(tpl(cfg.lookupTpl, { SID: sessionId }), {
            _token: token(),
            no_index: noIndex,
        }).done(function (res) {
            pendingBarang = res.barang;
            openColyModal(res.barang);
        }).fail(function (xhr) {
            var json = xhr.responseJSON;
            toast(
                (json && json.message) || t('generic_failed'),
                'error'
            );
            resetScanInput();
        });
    }

    function openColyModal(barang) {
        $('#modalNoIndex').text(barang.no_index);
        $('#modalMeta').text(
            (barang.marking || '') + (barang.customer_name ? ' · ' + barang.customer_name : ''),
        );
        $('#modalExpected').val(barang.expected_ctns);
        $('#modalActual').val(barang.expected_ctns);
        $('#modalVarianceNote').val('');
        syncVarianceUi();
        if (colyModal) {
            colyModal.show();
        }
    }

    function focusScanInput(delay) {
        setTimeout(function () {
            if ($('#scanWorkspace').hasClass('d-none')) {
                return;
            }
            var el = document.getElementById('scanInput');
            if (el) {
                el.focus({ preventScroll: true });
                el.select();
            }
        }, delay == null ? 150 : delay);
    }

    function resetScanInput(delay) {
        $('#scanInput').val('');
        focusScanInput(delay);
    }

    function syncVarianceUi() {
        var expected = parseInt($('#modalExpected').val(), 10) || 0;
        var actual = parseInt($('#modalActual').val(), 10);
        if (isNaN(actual)) {
            actual = expected;
        }
        var diff = actual !== expected;
        $('#varianceWrap').toggleClass('d-none', !diff);
    }

    function confirmColy() {
        if (!sessionId || !pendingBarang) {
            return;
        }
        var expected = parseInt($('#modalExpected').val(), 10) || 0;
        var actual = parseInt($('#modalActual').val(), 10);
        if (isNaN(actual) || actual < 0) {
            toast(t('actual_coly_invalid'), 'error');
            return;
        }
        $.post(tpl(cfg.confirmTpl, { SID: sessionId }), {
            _token: token(),
            barang_id: pendingBarang.id,
            confirmed_ctns: actual,
            variance_note: $('#modalVarianceNote').val(),
        }).done(function (res) {
            toast(res.message || t('success'));
            pendingBarang = null;
            renderSession(res.session);
            $('#scanInput').val('');
            if (colyModal) {
                colyModal.hide();
            } else {
                focusScanInput(200);
            }
        }).fail(function (xhr) {
            var json = xhr.responseJSON;
            toast((json && json.message) || t('generic_failed'), 'error');
            if (json && json.requires_note) {
                $('#varianceWrap').removeClass('d-none');
            }
        });
    }

    function completeSession() {
        if (!sessionId) {
            return;
        }
        $.post(tpl(cfg.completeTpl, { SID: sessionId }), {
            _token: token(),
            notes: $('#sessionNotes').val(),
        }).done(function (res) {
            toast(res.message || t('success'));
            if (res.redirect) {
                window.location.href = res.redirect;
                return;
            }
            window.location.href = cfg.indexUrl || '/bongkar-muat';
        }).fail(function (xhr) {
            toast((xhr.responseJSON && xhr.responseJSON.message) || t('generic_failed'), 'error');
        });
    }
});
