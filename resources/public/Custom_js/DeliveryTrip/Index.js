document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('deliveryTripTable')) {
        return;
    }

    var allData = [];
    var table = $('#deliveryTripTable').DataTable({
        paging: true,
        searching: false,
        ordering: false,
        info: true,
        lengthChange: false,
        pageLength: 25,
        columns: [
            { data: 'trip_no' },
            { data: 'truck_plate' },
            { data: 'driver_name' },
            { data: 'container_partai' },
            { data: 'item_count' },
            { data: 'status_html' },
            { data: 'action', orderable: false },
        ],
    });

    refreshData();

    $('#filterSearch').on('input', debounce(applyFilters, 200));
    $('#filterStatus').on('change', applyFilters);
    $('#btnResetFilter').on('click', function () {
        $('#filterSearch').val('');
        $('#filterStatus').val('');
        applyFilters();
    });

    function refreshData() {
        $.get('/delivery-trip/data', function (rows) {
            allData = (rows || []).map(formatRow);
            applyFilters();
        });
    }

    function applyFilters() {
        var search = ($('#filterSearch').val() || '').toLowerCase().trim();
        var status = $('#filterStatus').val();
        var filtered = allData.filter(function (row) {
            if (status && row._status !== status) {
                return false;
            }
            if (search && row._search.indexOf(search) === -1) {
                return false;
            }
            return true;
        });
        table.clear().rows.add(filtered).draw();
    }

    function formatRow(row) {
        var actions = [
            '<a href="/delivery-trip/' + row.id + '" class="btn btn-sm btn-glass"><i class="bi bi-eye"></i></a>'
        ];

        if (row.can_pdf && row.pdf_url) {
            actions.push(
                '<a href="' + esc(row.pdf_url) + '" class="btn btn-sm btn-glass" target="_blank" rel="noopener">' +
                '<i class="bi bi-file-earmark-pdf" style="color:#ef4444"></i>' +
                '</a>'
            );
        }

        return {
            trip_no: '<span class="fw-700">' + esc(row.trip_no) + '</span>',
            truck_plate: esc(row.truck_plate),
            driver_name: esc(row.driver_name),
            container_partai: esc(row.container_partai || '—'),
            item_count: String(row.item_count || 0),
            status_html: '<span class="badge-glass badge-status-' + esc(row.status) + '">' + esc(row.status_label) + '</span>',
            action: '<div class="d-inline-flex align-items-center gap-1 flex-nowrap text-nowrap">' + actions.join('') + '</div>',
            _status: row.status,
            _search: [row.trip_no, row.truck_plate, row.driver_name, row.container_partai].join(' ').toLowerCase(),
        };
    }

    function esc(s) {
        return $('<span>').text(s == null ? '' : s).html();
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            clearTimeout(t);
            var args = arguments;
            var ctx = this;
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }
});
