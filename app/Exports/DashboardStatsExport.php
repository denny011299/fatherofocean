<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Port;
use App\Models\Vessel;
use App\Models\WhatsAppDevice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

final class DashboardStatsExport implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return collect([
            ['Devices', WhatsAppDevice::query()->count()],
            ['Connected Devices', WhatsAppDevice::query()->where('status', 'connected')->count()],
            ['Total Vessels', Vessel::query()->count()],
            ['Total Ports', Port::query()->count()],
        ]);
    }

    public function headings(): array
    {
        return ['Metric', 'Value'];
    }
}
