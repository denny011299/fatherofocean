<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Port;
use App\Models\Vessel;
use App\Models\WhatsAppDevice;
use App\Services\WablasService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DashboardPage extends Component
{
    public ?WhatsAppDevice $activeDevice = null;

    public string $deviceStatus = 'disconnected';

    public ?string $qrCodeUrl = null;

    public int $trackingCount = 0;

    public int $vesselCount = 0;

    public int $portCount = 0;

    public function mount(): void
    {
        $this->syncStats();
    }

    public function syncStats(): void
    {
        $this->vesselCount = Vessel::query()->count();
        $this->portCount = Port::query()->count();
        $this->trackingCount = random_int(125, 980);
        $this->activeDevice = WhatsAppDevice::query()->latest()->first();

        if ($this->activeDevice !== null) {
            $this->deviceStatus = $this->activeDevice->status;
        }
    }

    public function checkDeviceStatus(WablasService $wablas): void
    {
        if ($this->activeDevice === null || $this->activeDevice->token === null) {
            $this->dispatch('toast', type: 'error', message: 'Token Wablas belum tersedia.');
            return;
        }

        $statusPayload = $wablas->checkDeviceStatus($this->activeDevice->token);
        $status = data_get($statusPayload, 'data.status', 'disconnected');

        $this->deviceStatus = strtolower((string) $status);
        $this->activeDevice->update([
            'status' => $this->deviceStatus,
            'last_synced_at' => now(),
        ]);

        if ($this->deviceStatus !== 'connected') {
            $scanPayload = $wablas->getQrCode($this->activeDevice->token);
            $this->qrCodeUrl = data_get($scanPayload, 'data.qr') ?: data_get($scanPayload, 'data.image');
        }

        $this->dispatch('toast', type: 'success', message: 'Status device berhasil diperbarui.');
    }

    public function render(): View
    {
        return view('livewire.dashboard-page')
            ->layout('layouts.app', ['title' => 'Dashboard']);
    }
}
