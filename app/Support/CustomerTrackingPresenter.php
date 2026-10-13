<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\TrackingStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Customer/public tracking must not surface warehouse-only "stuffing" auto updates
 * (e.g. "Barang masuk proses stuffing") — those cause miscommunication.
 *
 * Tanggal masuk barang must still remain visible (summary + softened warehouse row).
 */
final class CustomerTrackingPresenter
{
    /**
     * @return list<string>
     */
    public static function autoStuffingDescriptions(): array
    {
        $descriptions = [];
        foreach (['id', 'en', 'zh'] as $locale) {
            $text = trim((string) trans('tracking.auto_stuffing_description', [], $locale));
            if ($text !== '') {
                $descriptions[$text] = $text;
            }
        }

        return array_values($descriptions);
    }

    public static function isAutoStuffingEntry(?TrackingStatus $tracking): bool
    {
        if ($tracking === null) {
            return false;
        }

        if ((string) $tracking->status !== Barang::STATUS_STUFFING) {
            return false;
        }

        $description = trim((string) ($tracking->description ?? ''));

        return $description !== '' && in_array($description, self::autoStuffingDescriptions(), true);
    }

    /**
     * @param  Collection<int, TrackingStatus>|iterable<TrackingStatus>  $timeline
     * @return Collection<int, TrackingStatus>
     */
    public static function filterTimeline(iterable $timeline): Collection
    {
        return Collection::make($timeline)
            ->values()
            ->reject(static fn (TrackingStatus $row): bool => self::isAutoStuffingEntry($row))
            ->values();
    }

    /**
     * Customer-facing timeline: hide auto stuffing jargon, keep tanggal masuk visible.
     *
     * When the only history was auto-stuffing, synthesize one "Di Gudang / At warehouse"
     * row dated with barang.tanggal_masuk (or the stuffing event date).
     *
     * @param  Collection<int, TrackingStatus>|iterable<TrackingStatus>  $timeline
     * @return Collection<int, TrackingStatus>
     */
    public static function presentTimeline(Barang $barang, iterable $timeline): Collection
    {
        $filtered = self::filterTimeline($timeline)
            ->map(static function (TrackingStatus $row): TrackingStatus {
                $row->setAttribute('status_label', self::statusLabel((string) $row->status));
                $row->setAttribute(
                    'event_date_label',
                    TrackingEventDate::displayLabel($row->event_date ?? null, $row->created_at),
                );

                $description = trim((string) ($row->description ?? ''));
                if ($description !== '' && in_array($description, self::autoStuffingDescriptions(), true)) {
                    $row->setAttribute('description', (string) __('tracking.customer_notes_warehouse'));
                }

                return $row;
            })
            ->values();

        if ($filtered->isNotEmpty()) {
            return $filtered;
        }

        $warehouseEntry = self::syntheticWarehouseEntry($barang, $timeline);
        if ($warehouseEntry === null) {
            return $filtered;
        }

        return Collection::make([$warehouseEntry]);
    }

    /**
     * @param  Collection<int, TrackingStatus>|iterable<TrackingStatus>  $timeline
     */
    public static function syntheticWarehouseEntry(Barang $barang, iterable $timeline = []): ?TrackingStatus
    {
        $hiddenStuffing = Collection::make($timeline)
            ->first(static fn (TrackingStatus $row): bool => self::isAutoStuffingEntry($row));

        $masuk = $barang->tanggal_masuk;
        $status = Barang::normalizeShipmentStatus((string) ($barang->status ?? ''));
        $isWarehouseStatus = in_array($status, [Barang::STATUS_STUFFING, Barang::STATUS_INBOUND], true);

        if ($masuk === null && $hiddenStuffing === null && ! $isWarehouseStatus) {
            return null;
        }

        $eventDate = TrackingEventDate::normalize($masuk)
            ?? TrackingEventDate::normalize($hiddenStuffing?->event_date ?? null)
            ?? TrackingEventDate::normalize($hiddenStuffing?->created_at);

        $location = trim((string) ($hiddenStuffing?->location ?? ''));
        if ($location === '') {
            $location = trim((string) ($barang->warehouse_origin ?? ''));
        }
        if ($location === '' && $barang->relationLoaded('gudangChina') && $barang->gudangChina) {
            $location = trim((string) ($barang->gudangChina->name ?? ''));
        }

        $entry = new TrackingStatus([
            'status' => Barang::STATUS_STUFFING,
            'location' => $location !== '' ? $location : null,
            'description' => (string) __('tracking.customer_notes_warehouse'),
            'event_date' => $eventDate,
        ]);

        if ($hiddenStuffing?->created_at !== null) {
            $entry->setAttribute('created_at', $hiddenStuffing->created_at);
        } elseif ($masuk instanceof CarbonInterface) {
            $entry->setAttribute('created_at', $masuk->copy()->startOfDay());
        }

        $entry->setAttribute('status_label', self::statusLabel(Barang::STATUS_STUFFING));
        $entry->setAttribute(
            'event_date_label',
            TrackingEventDate::displayLabel($eventDate, $entry->created_at),
        );
        $entry->setAttribute('is_synthetic_warehouse', true);

        return $entry;
    }

    public static function tanggalMasukLabel(?Barang $barang): ?string
    {
        if ($barang?->tanggal_masuk === null) {
            return null;
        }

        return $barang->tanggal_masuk
            ->locale(app()->getLocale())
            ->translatedFormat('d M Y');
    }

    /**
     * Label field for customer track: "Tanggal Masuk Gudang Guangzhou/Yiwu".
     */
    public static function tanggalMasukFieldLabel(?Barang $barang): string
    {
        $warehouse = self::warehouseDisplayName($barang);
        if ($warehouse === null || $warehouse === '') {
            return (string) __('tracking.customer_tanggal_masuk_gudang_generic');
        }

        return (string) __('tracking.customer_tanggal_masuk_gudang', ['warehouse' => $warehouse]);
    }

    /**
     * Resolve customer-friendly China warehouse name (Guangzhou / Yiwu).
     */
    public static function warehouseDisplayName(?Barang $barang): ?string
    {
        if ($barang === null) {
            return null;
        }

        $candidates = [
            trim((string) ($barang->gudangChina?->name ?? '')),
            trim((string) ($barang->warehouse_origin ?? '')),
        ];

        foreach ($candidates as $raw) {
            if ($raw === '') {
                continue;
            }
            $normalized = self::normalizeWarehouseName($raw);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $noIndex = strtoupper(trim((string) ($barang->no_index ?? '')));
        if (str_starts_with($noIndex, 'YW') || str_starts_with($noIndex, 'YWON')) {
            return 'Yiwu';
        }
        if (str_starts_with($noIndex, 'GZ')) {
            return 'Guangzhou';
        }

        return null;
    }

    private static function normalizeWarehouseName(string $raw): ?string
    {
        if (preg_match('/guangzhou|guang\s*zhou|广州|\bgz\b/ui', $raw) === 1) {
            return 'Guangzhou';
        }

        if (preg_match('/yiwu|义乌|\byw\b/ui', $raw) === 1) {
            return 'Yiwu';
        }

        return null;
    }

    public static function statusLabel(string $status): string
    {
        $normalized = Barang::normalizeShipmentStatus($status);

        if ($normalized === Barang::STATUS_STUFFING || $normalized === Barang::STATUS_INBOUND) {
            return (string) __('tracking.customer_status_warehouse');
        }

        return Barang::shipmentStatusLabel($normalized);
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        $labels = [];
        foreach (Barang::SHIPMENT_STATUSES as $status) {
            $labels[$status] = self::statusLabel($status);
        }

        return $labels;
    }

    public static function publicNotes(?TrackingStatus $latest, string $barangStatus): string
    {
        if ($latest !== null && self::isAutoStuffingEntry($latest)) {
            return (string) __('tracking.customer_notes_warehouse');
        }

        if (
            $latest !== null
            && (string) $latest->status === $barangStatus
            && trim((string) ($latest->description ?? '')) !== ''
        ) {
            return trim((string) $latest->description);
        }

        return '-';
    }
}
