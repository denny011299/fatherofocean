<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Barang;
use App\Models\Container;
use App\Models\TrackingStatus;

final class BarangTrackingService
{
    public function syncStuffing(Barang $barang, ?int $createdBy = null): ?TrackingStatus
    {
        $location = trim((string) ($barang->warehouse_origin ?? ''));
        if ($location === '') {
            $barang->loadMissing('gudangChina:id,name');
            $location = trim((string) ($barang->gudangChina?->name ?? ''));
        }
        if ($location === '') {
            $location = (string) __('tracking.auto_stuffing_location_fallback');
        }

        $description = (string) __('tracking.auto_stuffing_description');

        if ($barang->trackingStatuses()->where('description', $description)->exists()) {
            return null;
        }

        return TrackingStatus::create([
            'barang_id' => $barang->id,
            'status' => Barang::STATUS_STUFFING,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
        ]);
    }

    public function syncContainerLoaded(Barang $barang, Container $container, ?int $createdBy = null): ?TrackingStatus
    {
        $container->loadMissing('gudangChina');

        $location = $container->gudangChina?->name
            ?? $container->alamat_gudang_china
            ?? __('tracking.auto_stuffing_location_fallback');

        $containerLabel = $container->partai ?: ($container->container_no ?: '#'.$container->id);

        return $this->recordIfMissing(
            $barang,
            Barang::STATUS_STUFFING,
            $location,
            __('tracking.auto_container_loaded_description', ['container' => $containerLabel]),
            $createdBy,
        );
    }

    private function recordIfMissing(
        Barang $barang,
        string $status,
        ?string $location,
        ?string $description,
        ?int $createdBy,
    ): ?TrackingStatus {
        if ($description !== null && $barang->trackingStatuses()->where('description', $description)->exists()) {
            return null;
        }

        if ($description === null && $barang->trackingStatuses()->where('status', $status)->exists()) {
            return null;
        }

        return TrackingStatus::create([
            'barang_id' => $barang->id,
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'created_by' => $createdBy,
        ]);
    }
}
