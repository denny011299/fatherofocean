<script>
document.addEventListener('DOMContentLoaded', function() {
    function formatCbm3CustomRound(value) {
        return typeof window.formatCbm === 'function'
            ? window.formatCbm(value)
            : Number(value || 0).toFixed(3);
    }

    const csrf = '{{ csrf_token() }}';
    const showTemplate = window.TRACKING_STAFF_SHOW_URL || '';
    const updateTemplate = window.TRACKING_STAFF_UPDATE_URL || '';
    const timelineUpdateTemplate = window.TRACKING_STAFF_TIMELINE_UPDATE_URL || '';
    const timelineDeleteTemplate = window.TRACKING_STAFF_TIMELINE_DELETE_URL || '';
    const canManageTimeline = !!window.TRACKING_CAN_MANAGE_TIMELINE;
    const sjSummaryTemplate = window.TRACKING_STAFF_DELIVERY_TRIP_SUMMARY_URL || '';
    const sjUpsertTemplate = window.TRACKING_STAFF_DELIVERY_TRIP_UPSERT_URL || '';
    const sjDepartTemplate = window.DELIVERY_TRIP_DEPART_URL || '';
    const sjArriveTemplate = window.DELIVERY_TRIP_ARRIVE_URL || '';
    const sjModalEl = document.getElementById('deliveryTripModal');
    const sjModal = sjModalEl ? bootstrap.Modal.getOrCreateInstance(sjModalEl) : null;
    const $sjRoot = $('#deliveryTripModalBody');
    const sjState = {
        csrf: csrf,
        summaryUrl: sjSummaryTemplate,
        upsertUrl: sjUpsertTemplate,
        departUrlTemplate: sjDepartTemplate,
        arriveUrlTemplate: sjArriveTemplate,
        canBlankTemplate: @json(auth()->user()?->hasRole('super_admin')),
        currentContainerId: null,
        currentTripId: null,
        markingsByKey: {},
        onRendered: function (payload) {
            $('#sjSubtitle').text(
                (payload.container?.partai || '-') +
                (payload.container?.container_no ? ' · ' + payload.container.container_no : '')
            );
        },
    };
    if ($sjRoot.length && window.SuratJalanUi) {
        window.SuratJalanUi.bind($sjRoot, sjState);
    }

    let timelineContainerId = null;
    let timelineContainerLabel = '';
    let reopenTimelineAfterEdit = false;
    let refreshTimelineAfterEdit = false;

    (function() {
        var $dest = $('#destinationFilter');
        if (!$dest.length) return;
        var selected = $dest.data('selected') || '';
        $.ajax({
            url: '/master-data/cities/options',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                $.each(data, function(_, item) {
                    if (!item.is_active) return;
                    var isSelected = (item.name === selected) ? ' selected' : '';
                    $dest.append('<option value="' + item.name + '"' + isSelected + '>' + item.name + '</option>');
                });
            }
        });
    })();

    $(document).on('click', '.tracking-container-row.has-expandable .tracking-expand-hit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = $(this).closest('tr.tracking-container-row');
        var open = !$row.hasClass('is-expanded');
        var labelExpand = @json(__('tracking.expand_details'));
        var labelCollapse = @json(__('tracking.collapse_details'));

        $row.toggleClass('is-expanded', open).attr('aria-expanded', open ? 'true' : 'false');
        $row.find('.tracking-expand-cue').attr('title', open ? labelCollapse : labelExpand);

        $row.find('.tracking-expand-text').each(function () {
            var $el = $(this);
            var shortText = $el.attr('data-short') || '';
            var fullText = $el.attr('data-full') || '';
            $el.toggleClass('is-collapsed', !open);
            $el.text(open ? fullText : shortText);
        });
    });

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function updateContainerTableRow(container) {
        if (!container || !container.id) {
            return;
        }

        const $row = $('.btn-timeline[data-id="' + container.id + '"]').closest('tr.tracking-container-row');
        if (!$row.length) {
            return;
        }

        const badge = container.status_badge || '';
        const label = container.status_label || '-';
        $row.children('td').eq(5).html(
            '<span class="badge-glass ' + escapeHtml(badge) + '">' + escapeHtml(label) + '</span>'
        );
        $row.children('td').eq(6).text(container.last_location || '-');
        $row.find('.tracking-notes-text').text(container.last_description || '-');
    }

    function loadContainerTimeline(id, label) {
        timelineContainerId = id;
        timelineContainerLabel = label || '';
        $('#tlTitle').text(timelineContainerLabel);
        $('#tlSummary').html('<div class="text-muted-glass small">Memuat...</div>');
        $('#timelineContent').html('<div class="text-center py-4 text-muted-glass"><div class="spinner-border spinner-border-sm"></div> Memuat...</div>');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('timelineModal')).show();

        $.get(showTemplate.replace('__CONTAINER_ID__', String(id)), function(res) {
            const c = res.container;
            updateContainerTableRow(c);
            const manage = canManageTimeline && !!res.can_manage_timeline;
            let summary = `<div class="row g-2">
                <div class="col-5"><div class="text-muted-glass" style="font-size:.65rem">CONTAINER</div><div class="text-white fw-700">${escapeHtml(c.partai || '-')}</div></div>
                <div class="col-3"><div class="text-muted-glass" style="font-size:.65rem">CTNS</div><div class="text-white fw-600">${escapeHtml(c.total_ctns)}</div></div>
                <div class="col-4"><div class="text-muted-glass" style="font-size:.65rem">STATUS</div><div class="text-white fw-600">${escapeHtml(c.status_label || '-')}</div></div>
                <div class="col-4"><div class="text-muted-glass" style="font-size:.65rem">CONTAINER NO</div><div class="text-white fw-600">${escapeHtml(c.container_no || '-')}</div></div>
                <div class="col-4"><div class="text-muted-glass" style="font-size:.65rem">KIRIMAN</div><div class="text-white fw-600">${escapeHtml(c.shipments_count)}</div></div>
                <div class="col-4"><div class="text-muted-glass" style="font-size:.65rem">CBM</div><div class="text-accent fw-700">${formatCbm3CustomRound(c.total_cbm)}</div></div>
            </div>`;

            if (c.markings && c.markings !== '-') {
                summary += `<div class="mt-2 text-muted-glass small"><i class="bi bi-upc me-1"></i>${escapeHtml(c.markings)}</div>`;
            }
            if (c.customers && c.customers !== '-') {
                summary += `<div class="text-muted-glass small"><i class="bi bi-person me-1"></i>${escapeHtml(c.customers)}</div>`;
            }
            if (c.destinations && c.destinations !== '-') {
                summary += `<div class="text-muted-glass small"><i class="bi bi-geo-alt me-1"></i>${escapeHtml(c.destinations)}</div>`;
            }

            $('#tlSummary').html(summary);

            let html = '<div class="timeline">';
            if (!res.timeline.length) {
                html += '<p class="text-muted-glass text-center py-3">Belum ada data tracking.</p>';
            }

            res.timeline.forEach(function(t, i) {
                const cls = i === 0 ? 'active' : 'completed';
                const ico = getStatusIcon(t.status);
                const actions = manage
                    ? `<div class="d-flex gap-1 ms-2 flex-shrink-0">
                        <button type="button" class="btn btn-glass btn-sm btn-edit-timeline"
                            data-id="${t.id}"
                            data-status="${escapeHtml(t.status || '')}"
                            data-location="${escapeHtml(t.location || '')}"
                            data-description="${escapeHtml(t.description || '')}"
                            title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-glass btn-sm text-danger btn-delete-timeline"
                            data-id="${t.id}"
                            data-label="${escapeHtml(t.status_label || t.status || '')}"
                            title="Hapus">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>`
                    : '';

                html += `<div class="timeline-item ${cls}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="fw-600 text-white"><i class="bi ${ico} me-1"></i>${escapeHtml(t.status_label || t.status)}</div>
                        ${actions}
                    </div>
                    ${t.location ? `<div class="text-accent small"><i class="bi bi-pin-map me-1"></i>${escapeHtml(t.location)}</div>` : ''}
                    ${t.description ? `<div class="text-muted-glass small mt-1">${escapeHtml(t.description)}</div>` : ''}
                    ${t.creator ? `<div class="text-muted-glass" style="font-size:.65rem"><i class="bi bi-person me-1"></i>${escapeHtml(t.creator.name)}</div>` : ''}
                </div>`;
            });
            html += '</div>';
            $('#timelineContent').html(html);
        }).fail(function(xhr) {
            $('#timelineContent').html('<div class="text-center py-4 text-danger">' + (xhr.responseJSON?.message || 'Gagal memuat timeline.') + '</div>');
        });
    }

    $(document).on('click', '.btn-timeline', function() {
        loadContainerTimeline($(this).data('id'), $(this).data('label') || '');
    });

    function getStatusIcon(status) {
        const map = {
            'loading': 'bi-box-seam',
            'etd': 'bi-water',
            'eta': 'bi-pin-map-fill',
            'bea_cukai': 'bi-shield-check',
            'bongkar': 'bi-check-circle-fill',
        };
        return map[status] || 'bi-circle';
    }

    $(document).on('click', '.btn-update-status', function() {
        $('#usContainerId').val($(this).data('id'));
        $('#usTitle').text($(this).data('label') || '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('updateStatusModal')).show();
    });

    $('#updateStatusForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#usContainerId').val();

        $.ajax({
            url: updateTemplate.replace('__CONTAINER_ID__', String(id)),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: $(this).serialize(),
            success(res) {
                showToast(res.message);
                if (res.invoice_warning) {
                    showToast(res.invoice_warning, 'warning');
                }
                bootstrap.Modal.getInstance(document.getElementById('updateStatusModal'))?.hide();
                setTimeout(() => location.reload(), 800);
            },
            error(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal', 'error');
            }
        });
    });

    function openEditTimelineModal() {
        const timelineEl = document.getElementById('timelineModal');
        const editEl = document.getElementById('editTimelineModal');
        if (!timelineEl || !editEl) {
            return;
        }

        reopenTimelineAfterEdit = true;
        refreshTimelineAfterEdit = false;

        const editModal = function () {
            if (window.getBootstrapModal) {
                return window.getBootstrapModal(editEl, { backdrop: true, keyboard: true });
            }
            return bootstrap.Modal.getOrCreateInstance(editEl, { backdrop: true, keyboard: true });
        };

        const ensureEditBackdrop = function () {
            if (!document.querySelector('.modal-backdrop')) {
                const bd = document.createElement('div');
                bd.className = 'modal-backdrop fade show';
                document.body.appendChild(bd);
            }
            document.body.classList.add('modal-open');
        };

        const showEdit = function () {
            timelineEl.removeEventListener('hidden.bs.modal', showEdit);
            // Wait past syncBootstrapModalState (setTimeout 0) so it does not
            // wipe the new backdrop before editTimelineModal gets .show.
            setTimeout(function () {
                editEl.addEventListener('shown.bs.modal', ensureEditBackdrop, { once: true });
                editModal().show();
            }, 50);
        };

        // Hide Timeline Container first so only one modal/backdrop is visible.
        if (timelineEl.classList.contains('show')) {
            timelineEl.addEventListener('hidden.bs.modal', showEdit);
            bootstrap.Modal.getOrCreateInstance(timelineEl).hide();
        } else {
            showEdit();
        }
    }

    function restoreTimelineAfterEdit() {
        if (!reopenTimelineAfterEdit) {
            return;
        }
        reopenTimelineAfterEdit = false;

        const shouldRefresh = refreshTimelineAfterEdit;
        refreshTimelineAfterEdit = false;
        const containerId = timelineContainerId;

        if (shouldRefresh && containerId) {
            setTimeout(function () {
                loadContainerTimeline(containerId, timelineContainerLabel);
            }, 50);
            return;
        }

        const timelineEl = document.getElementById('timelineModal');
        if (timelineEl) {
            setTimeout(function () {
                bootstrap.Modal.getOrCreateInstance(timelineEl).show();
            }, 50);
        }
    }

    $(document).on('click', '.btn-edit-timeline', function() {
        const $btn = $(this);
        $('#etContainerId').val(timelineContainerId);
        $('#etEntryId').val($btn.data('id'));
        $('#etTitle').text(timelineContainerLabel || '');
        $('#etStatus').val($btn.data('status') || '');
        $('#etLocation').val($btn.attr('data-location') || '');
        $('#etDescription').val($btn.attr('data-description') || '');
        openEditTimelineModal();
    });

    $('#editTimelineModal').on('hidden.bs.modal', function () {
        restoreTimelineAfterEdit();
    });

    $('#editTimelineForm').on('submit', function(e) {
        e.preventDefault();
        const containerId = $('#etContainerId').val();
        const entryId = $('#etEntryId').val();
        if (!timelineUpdateTemplate || !containerId || !entryId) {
            showToast('Endpoint edit timeline belum dikonfigurasi.', 'error');
            return;
        }

        const url = timelineUpdateTemplate
            .replace('__CONTAINER_ID__', String(containerId))
            .replace('__ENTRY_ID__', String(entryId));

        $.ajax({
            url: url,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: $(this).serialize(),
            success(res) {
                showToast(res.message || 'Timeline diperbarui');
                refreshTimelineAfterEdit = true;
                bootstrap.Modal.getInstance(document.getElementById('editTimelineModal'))?.hide();
            },
            error(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal menyimpan timeline', 'error');
            }
        });
    });

    $(document).on('click', '.btn-delete-timeline', function() {
        const entryId = $(this).data('id');
        const label = $(this).data('label') || 'entri ini';
        const containerId = timelineContainerId;

        if (!timelineDeleteTemplate || !containerId || !entryId) {
            showToast('Endpoint hapus timeline belum dikonfigurasi.', 'error');
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Hapus entri timeline?',
            html: 'Entri <strong>' + escapeHtml(label) + '</strong> akan dihapus. Status tampilan akan mengikuti entri terbaru yang tersisa.',
            showCancelButton: true,
            confirmButtonText: 'Ya, hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#fb7185',
        }).then(function(result) {
            if (!result.isConfirmed) return;

            const url = timelineDeleteTemplate
                .replace('__CONTAINER_ID__', String(containerId))
                .replace('__ENTRY_ID__', String(entryId));

            $.ajax({
                url: url,
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf },
                success(res) {
                    showToast(res.message || 'Timeline dihapus');
                    loadContainerTimeline(containerId, timelineContainerLabel);
                },
                error(xhr) {
                    showToast(xhr.responseJSON?.message || 'Gagal menghapus timeline', 'error');
                }
            });
        });
    });

    $(document).on('click', '.btn-surat-jalan', function() {
        sjState.currentContainerId = $(this).data('id');
        sjState.currentTripId = null;
        $('#sjTitle').text(' - ' + ($(this).data('label') || ''));
        $('#sjSubtitle').text('Memuat data surat jalan...');
        if (window.SuratJalanUi && $sjRoot.length) {
            window.SuratJalanUi.reset($sjRoot);
            sjModal?.show();
            window.SuratJalanUi.load($sjRoot, sjState.currentContainerId, sjState);
        }
    });
});
</script>
