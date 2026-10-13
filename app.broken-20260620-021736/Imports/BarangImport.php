<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Barang;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BarangImport implements ToModel, WithHeadingRow
{
    public function model(array $row): ?Barang
    {
        if (empty($row['no_index'])) {
            return null;
        }

        return Barang::firstOrCreate(
            ['no_index' => $row['no_index']],
            [
                'marking' => $row['marking'] ?? null,
                'warehouse_origin' => $row['warehouse_origin'] ?? 'guangzhou',
                'tanggal_masuk' => $row['tanggal_masuk'] ?? now(),
                'ctns' => $row['ctns'] ?? 0,
                'pcs' => $row['pcs'] ?? 0,
                'material' => $row['material'] ?? null,
                'destination' => $row['destination'] ?? null,
                'notes' => $row['notes'] ?? null,
                'status' => 'draft',
                'is_preload' => true,
                'created_by' => auth()->id(),
            ]
        );
    }
}
