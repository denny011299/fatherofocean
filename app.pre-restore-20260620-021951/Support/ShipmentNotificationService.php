<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\DeliveryTrip;
use App\Models\RepricingRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

final class ShipmentNotificationService
{
    /**
     * @return Collection<int, int>
     */
    public static function barangCreatedRecipientIds(Barang $barang, ?int $exceptUserId = null): Collection
    {
        $markingValue = trim((string) ($barang->marking ?? ''));

        return self::eligibleStaffUsers($exceptUserId)
            ->filter(static fn (User $user): bool => NotificationScope::userShouldReceiveBarangNotification(
                $user,
                $markingValue,
            ))
            ->pluck('id');
    }

    /**
     * @return Collection<int, int>
     */
    public static function generalShipmentRecipientIds(?int $exceptUserId = null): Collection
    {
        return self::eligibleStaffUsers($exceptUserId)
            ->filter(static fn (User $user): bool => NotificationScope::userShouldReceiveGeneralShipmentNotification($user))
            ->pluck('id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private static function eligibleStaffUsers(?int $exceptUserId = null): \Illuminate\Support\Collection
    {
        $query = User::query()
            ->where('is_marking_portal', false)
            ->permission('view barang')
            ->whereDoesntHave('roles', static function ($q): void {
                $q->where('name', 'customer');
            });

        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, int>
     */
    public static function repricingApproverUserIds(?int $exceptUserId = null): Collection
    {
        $query = User::query()
            ->where('is_marking_portal', false)
            ->permission('approve repricing');

        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->pluck('id');
    }

    public static function notifyBarangCreated(Barang $barang, ?int $actorId = null): void
    {
        $barang->refresh();
        $actorName = $actorId ? User::query()->whereKey($actorId)->value('name') : null;
        $title = __('notifications.barang_created_title');
        $body = __('notifications.barang_created_body', [
            'no_index' => $barang->no_index,
            'marking' => $barang->marking ?? '—',
            'actor' => $actorName ?? __('notifications.system'),
        ]);

        self::dispatch(
            self::barangCreatedRecipientIds($barang, $actorId),
            UserNotification::TYPE_BARANG_CREATED,
            $title,
            $body,
            [
                'barang_id' => $barang->id,
                'no_index' => $barang->no_index,
                'marking' => $barang->marking,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'url' => route('barang.edit', $barang),
            ],
        );
    }

    public static function notifyDeliveryTripCreated(DeliveryTrip $trip, ?int $actorId = null): void
    {
        $trip->loadCount('barangs');
        $actorName = $actorId ? User::query()->whereKey($actorId)->value('name') : null;
        $title = __('notifications.delivery_trip_created_title');
        $body = __('notifications.delivery_trip_created_body', [
            'trip_no' => $trip->trip_no,
            'plate' => $trip->truck_plate,
            'count' => $trip->barangs_count,
            'actor' => $actorName ?? __('notifications.system'),
        ]);

        self::dispatch(
            self::generalShipmentRecipientIds($actorId),
            UserNotification::TYPE_DELIVERY_TRIP_CREATED,
            $title,
            $body,
            [
                'delivery_trip_id' => $trip->id,
                'trip_no' => $trip->trip_no,
                'actor_id' => $actorId,
                'url' => route('delivery-trip.show', $trip),
            ],
        );
    }

    public static function notifyRepricingSubmitted(RepricingRequest $request, ?int $actorId = null): void
    {
        $request->loadMissing('barang');
        $barang = $request->barang;
        if ($barang === null) {
            return;
        }

        $actorName = $actorId ? User::query()->whereKey($actorId)->value('name') : null;
        $title = __('notifications.repricing_submitted_title');
        $body = __('notifications.repricing_submitted_body', [
            'no_index' => $barang->no_index,
            'actor' => $actorName ?? __('notifications.system'),
        ]);

        self::dispatch(
            self::repricingApproverUserIds($actorId),
            UserNotification::TYPE_REPRICING_SUBMITTED,
            $title,
            $body,
            [
                'repricing_request_id' => $request->id,
                'barang_id' => $barang->id,
                'no_index' => $barang->no_index,
                'url' => route('finance.pricing', ['tab' => 'pengajuan']),
            ],
        );
    }

    /**
     * @param  Collection<int, int>|list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private static function dispatch(
        Collection|array $userIds,
        string $type,
        string $title,
        string $body,
        array $data,
    ): void {
        $ids = $userIds instanceof Collection ? $userIds->all() : $userIds;
        if ($ids === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($ids as $userId) {
            $rows[] = [
                'user_id' => (int) $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        UserNotification::query()->insert($rows);
    }
}
