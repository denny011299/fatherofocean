<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Rekapan Surat Jalan</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h2 { margin: 0 0 10px 0; font-size: 14px; }

        .table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .table col.c-no { width: 5.5%; }
        .table col.c-marking { width: 10%; }
        .table col.c-item { width: 13%; }
        .table col.c-coly { width: 3.2%; }
        .table col.c-total-coly { width: 3.2%; }
        .table col.c-cbm { width: 4%; }
        .table col.c-kg { width: 4%; }
        .table col.c-total-cbm { width: 5%; }
        .table col.c-total-kg { width: 5%; }
        .table col.c-polisi { width: 7%; }
        .table col.c-driver { width: 8%; }
        .table col.c-phone { width: 7%; }
        .table col.c-alamat { width: 16%; }
        .table col.c-notes { width: 9%; }

        .table thead { display: table-header-group; }
        .table tfoot { display: table-row-group; }

        .table th,
        .table td {
            border: 1px solid #222;
            padding: 4px 5px;
            vertical-align: top;
            line-height: 1.25;
            page-break-inside: avoid;
        }

        .table th {
            text-align: center;
            background: #f3f3f3;
            font-weight: bold;
            white-space: nowrap;
        }

        .table th.th-no,
        .table th.th-marking,
        .table th.th-item {
            white-space: normal;
            line-height: 1.2;
        }

        .center { text-align: center; }
        .right { text-align: right; }

        .cell-marking,
        .cell-item,
        .cell-alamat,
        .cell-notes {
            white-space: normal;
            word-break: break-word;
            overflow-wrap: break-word;
            line-height: 1.25;
        }

        .cell-no {
            white-space: normal;
            word-break: normal;
            overflow-wrap: break-word;
        }

        .cell-marking {
            vertical-align: top;
            text-align: center;
            font-weight: bold;
        }

        .cell-total {
            vertical-align: middle;
            text-align: right;
            font-weight: bold;
            background: #fafafa;
        }

        tr.group-start td {
            border-top-width: 1.4px;
        }
    </style>
</head>
<body>
    <h2>Rekapan Surat Jalan</h2>

    <table class="table">
        <colgroup>
            <col class="c-no">
            <col class="c-marking">
            <col class="c-item">
            <col class="c-coly">
            <col class="c-total-coly">
            <col class="c-cbm">
            <col class="c-kg">
            <col class="c-total-cbm">
            <col class="c-total-kg">
            <col class="c-polisi">
            <col class="c-driver">
            <col class="c-phone">
            <col class="c-telp">
            <col class="c-alamat">
            <col class="c-notes">
        </colgroup>
        <thead>
            <tr>
                <th class="th-no">No Urut</th>
                <th class="th-marking">Marking</th>
                <th class="th-item">Item</th>
                <th>Coly</th>
                <th>Total Coly</th>
                <th>CBM</th>
                <th>KG</th>
                <th>Total CBM</th>
                <th>Total KG</th>
                <th>No Polisi</th>
                <th>Nama Supir</th>
                <th>No HP Supir</th>
                <th>Telp</th>
                <th>Alamat</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr class="{{ !empty($row['show_group']) ? 'group-start' : '' }}">
                <td class="center cell-no">{{ $row['no_urut'] !== '' ? $row['no_urut'] : '-' }}</td>
                <td class="cell-marking">{{ $row['marking'] !== '' ? $row['marking'] : '-' }}</td>
                <td class="cell-item">{{ $row['item'] }}</td>
                <td class="center">{{ $row['coly'] !== '' ? $row['coly'] : '-' }}</td>
                <td class="center cell-total">{{ ($row['total_coly'] ?? '') !== '' ? $row['total_coly'] : '-' }}</td>
                <td class="right">{{ $row['cbm'] !== '' ? $row['cbm'] : '-' }}</td>
                <td class="right">{{ $row['kg'] }}</td>
                <td class="cell-total">{{ $row['total_cbm'] ?? '' }}</td>
                <td class="cell-total">{{ $row['total_kg'] ?? '' }}</td>
                <td class="center">{{ $row['truck_plate'] }}</td>
                <td>{{ $row['driver_name'] }}</td>
                <td class="center">{{ $row['driver_phone'] }}</td>
                <td class="center">{{ $row['telp'] ?? '' }}</td>
                <td class="cell-alamat">{{ $row['alamat'] }}</td>
                <td class="cell-notes">{{ $row['notes'] }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="15" class="center">Belum ada data surat jalan.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
