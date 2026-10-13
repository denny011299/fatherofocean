<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Support\NotificationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UserNotificationController extends Controller
{
    public function index(): View
    {
        return view('notifications.index');
    }

    public function list(Request $request): JsonResponse
    {
        $userId = auth()->id();
        abort_if($userId === null, 403);

        $limit = min(50, max(5, (int) $request->query('limit', 20)));

        $items = $this->scopedQuery($userId)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (UserNotification $n) => $this->formatNotification($n));

        return response()->json([
            'unread_count' => $this->unreadCount($userId),
            'items' => $items,
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $userId = auth()->id();
        abort_if($userId === null, 403);

        $sinceId = (int) $request->query('since_id', 0);

        $newItems = $sinceId > 0
            ? $this->scopedQuery($userId)
                ->where('id', '>', $sinceId)
                ->orderBy('id')
                ->get()
            : collect();

        return response()->json([
            'unread_count' => $this->unreadCount($userId),
            'latest_id' => (int) $this->scopedQuery($userId)->max('id'),
            'items' => $newItems->map(fn (UserNotification $n) => $this->formatNotification($n)),
        ]);
    }

    public function unreadCountEndpoint(): JsonResponse
    {
        $userId = auth()->id();
        abort_if($userId === null, 403);

        return response()->json([
            'unread_count' => $this->unreadCount($userId),
            'latest_id' => (int) $this->scopedQuery($userId)->max('id'),
        ]);
    }

    public function markRead(UserNotification $userNotification): JsonResponse
    {
        $this->authorizeNotification($userNotification);
        if ($userNotification->read_at === null) {
            $userNotification->update(['read_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount((int) auth()->id()),
        ]);
    }

    public function markAllRead(): JsonResponse
    {
        $userId = auth()->id();
        abort_if($userId === null, 403);

        $this->scopedQuery($userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    private function unreadCount(int $userId): int
    {
        return $this->scopedQuery($userId)
            ->whereNull('read_at')
            ->count();
    }

    /** @return Builder<UserNotification> */
    private function scopedQuery(int $userId): Builder
    {
        return NotificationScope::applyVisibleScope(
            UserNotification::query()->where('user_id', $userId),
        );
    }

    private function authorizeNotification(UserNotification $notification): void
    {
        abort_unless((int) auth()->id() === (int) $notification->user_id, 403);

        $visible = NotificationScope::applyVisibleScope(
            UserNotification::query()->whereKey($notification->id),
        )->exists();

        abort_unless($visible, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNotification(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url(),
            'icon' => $n->iconClass(),
            'is_read' => $n->read_at !== null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
            'created_at_display' => $n->created_at?->locale(app()->getLocale())->diffForHumans(),
        ];
    }
}
