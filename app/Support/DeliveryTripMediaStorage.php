<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMedia;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DeliveryTripMediaStorage
{
    private const PHOTO_MIMES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo', 'video/3gpp'];

    public const MAX_PHOTO_KB = 10240;

    public const MAX_VIDEO_KB = 51200;

    /**
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, UploadedFile>  $videos
     * @return list<DeliveryTripMedia>
     */
    public static function storePhase(
        DeliveryTrip $trip,
        string $phase,
        array $photos,
        array $videos,
        ?int $userId,
        ?string $markingKey = null,
    ): array {
        if (! in_array($phase, [DeliveryTripMedia::PHASE_DEPARTURE, DeliveryTripMedia::PHASE_ARRIVAL], true)) {
            throw new InvalidArgumentException('Invalid delivery trip phase.');
        }

        if ($photos === [] || $videos === []) {
            throw new InvalidArgumentException(__('delivery_trip.media_requires_both'));
        }

        $saved = [];
        $markingSegment = $markingKey !== null && $markingKey !== ''
            ? '/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $markingKey)
            : '';
        $baseDir = 'delivery-trips/'.$trip->id.$markingSegment.'/'.$phase;

        foreach ($photos as $file) {
            $saved[] = self::storeOne($trip, $phase, DeliveryTripMedia::TYPE_PHOTO, $file, $baseDir.'/photos', $userId, $markingKey);
        }

        foreach ($videos as $file) {
            $saved[] = self::storeOne($trip, $phase, DeliveryTripMedia::TYPE_VIDEO, $file, $baseDir.'/videos', $userId, $markingKey);
        }

        return $saved;
    }

    private static function storeOne(
        DeliveryTrip $trip,
        string $phase,
        string $mediaType,
        UploadedFile $file,
        string $directory,
        ?int $userId,
        ?string $markingKey = null,
    ): DeliveryTripMedia {
        $mime = strtolower((string) $file->getMimeType());
        if ($mediaType === DeliveryTripMedia::TYPE_PHOTO) {
            if (! in_array($mime, self::PHOTO_MIMES, true)) {
                throw new InvalidArgumentException(__('delivery_trip.invalid_photo_file'));
            }
            $path = UploadedImageCompressor::storeForDeliveryTrip($file, $directory, 'public');
        } else {
            if (! in_array($mime, self::VIDEO_MIMES, true)) {
                throw new InvalidArgumentException(__('delivery_trip.invalid_video_file'));
            }
            $path = $file->store($directory, 'public');
        }

        $storedSize = Storage::disk('public')->exists($path)
            ? (int) Storage::disk('public')->size($path)
            : $file->getSize();

        return DeliveryTripMedia::query()->create([
            'delivery_trip_id' => $trip->id,
            'marking_key' => $markingKey,
            'phase' => $phase,
            'media_type' => $mediaType,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mediaType === DeliveryTripMedia::TYPE_PHOTO ? 'image/jpeg' : $mime,
            'file_size' => $storedSize,
            'uploaded_by' => $userId,
        ]);
    }

    public static function deleteTripFiles(DeliveryTrip $trip): void
    {
        $disk = Storage::disk('public');
        $disk->deleteDirectory('delivery-trips/'.$trip->id);
    }
}
