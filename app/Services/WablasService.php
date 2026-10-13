<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class WablasService
{
    public function checkDeviceStatus(string $token): array
    {
        return $this->client($token)
            ->get('/api/device/info')
            ->json() ?? [];
    }

    public function getQrCode(string $token): array
    {
        return $this->client($token)
            ->get('/api/device/scan')
            ->json() ?? [];
    }

    public function sendMessage(string $token, string $phone, string $message): array
    {
        return $this->client($token)
            ->post('/api/send-message', [
                'phone' => $phone,
                'message' => $message,
            ])
            ->json() ?? [];
    }

    private function client(string $token): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.wablas.base_url', 'https://tegal.wablas.com');

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->timeout(20)
            ->withQueryParameters(['token' => $token]);
    }
}
