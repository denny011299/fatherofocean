<?php

declare(strict_types=1);

namespace App\Livewire\WhatsApp;

use App\Models\WhatsAppDevice;
use App\Services\WablasService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DeviceManager extends Component
{
    public string $name = 'Primary Device';

    public string $token = '';

    public string $status = 'disconnected';

    public ?string $qrCodeUrl = null;

    public function mount(): void
    {
        $device = WhatsAppDevice::query()->latest()->first();

        if ($device !== null) {
            $this->name = $device->name;
            $this->token = $device->token;
            $this->status = $device->status;
        }
    }

    public function saveToken(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'token' => ['required', 'string', 'min:10'],
        ]);

        WhatsAppDevice::query()->updateOrCreate(
            ['name' => $this->name],
            [
                'token' => $this->token,
                'status' => $this->status,
                'last_synced_at' => now(),
            ],
        );

        $this->dispatch('toast', type: 'success', message: 'Token device tersimpan.');
    }

    public function refreshStatus(WablasService $wablas): void
    {
        $device = WhatsAppDevice::query()->where('name', $this->name)->first();

        if ($device === null) {
            $this->dispatch('toast', type: 'error', message: 'Simpan token terlebih dahulu.');
            return;
        }

        $statusPayload = $wablas->checkDeviceStatus($device->token);
        $this->status = strtolower((string) data_get($statusPayload, 'data.status', 'disconnected'));

        $device->update([
            'status' => $this->status,
            'last_synced_at' => now(),
        ]);

        if ($this->status !== 'connected') {
            $scanPayload = $wablas->getQrCode($device->token);
            $this->qrCodeUrl = data_get($scanPayload, 'data.qr') ?: data_get($scanPayload, 'data.image');
        } else {
            $this->qrCodeUrl = null;
        }

        $this->dispatch('toast', type: 'success', message: 'Status device diperbarui.');
    }

    public function render(): View
    {
        return view('livewire.whatsapp.device-manager')
            ->layout('layouts.app', ['title' => 'WhatsApp Device Manager']);
    }
}
