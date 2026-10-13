<?php

declare(strict_types=1);

namespace App\Livewire\MasterData;

use App\Models\Port;
use App\Models\Vessel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ShipPortManager extends Component
{
    public string $vesselName = '';

    public string $vesselCode = '';

    public string $portName = '';

    public string $portCode = '';

    public string $portCity = '';

    public function createVessel(): void
    {
        $this->validate([
            'vesselName' => ['required', 'string', 'max:100'],
            'vesselCode' => ['required', 'string', 'max:20'],
        ]);

        Vessel::query()->create([
            'name' => $this->vesselName,
            'code' => strtoupper($this->vesselCode),
            'status' => 'active',
        ]);

        $this->reset(['vesselName', 'vesselCode']);
        $this->dispatch('toast', type: 'success', message: 'Data kapal ditambahkan.');
    }

    public function createPort(): void
    {
        $this->validate([
            'portName' => ['required', 'string', 'max:100'],
            'portCode' => ['required', 'string', 'max:20'],
            'portCity' => ['required', 'string', 'max:100'],
        ]);

        Port::query()->create([
            'name' => $this->portName,
            'code' => strtoupper($this->portCode),
            'city' => $this->portCity,
        ]);

        $this->reset(['portName', 'portCode', 'portCity']);
        $this->dispatch('toast', type: 'success', message: 'Data pelabuhan ditambahkan.');
    }

    public function render(): View
    {
        return view('livewire.master-data.ship-port-manager', [
            'vessels' => Vessel::query()->latest()->limit(8)->get(),
            'ports' => Port::query()->latest()->limit(8)->get(),
        ])->layout('layouts.app', ['title' => 'Master Data']);
    }
}
