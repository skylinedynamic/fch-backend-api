<?php

namespace App\Services\FCH\Common\Notification;

use App\Events\NotificationsReadEvent;
use App\Models\User\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * The signed-in user's notification inbox. Everything goes through
 * $user->notifications(), so a user can never see or change anyone else's.
 */
class NotificationInboxService
{
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? config('notifications.inbox.per_page', 15));

        return $user->notifications()
            ->when(($filters['status'] ?? null) === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(($filters['status'] ?? null) === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->reorder()
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markRead(User $user, string $id): ?DatabaseNotification
    {
        $notification = $this->find($user, $id);

        if (!$notification) {
            return null;
        }

        $notification->markAsRead();
        $this->announce($user, [$id]);

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        $updated = $user->unreadNotifications()->update(['read_at' => now()]);
        $this->announce($user, null);

        return $updated;
    }

    public function delete(User $user, string $id): bool
    {
        $notification = $this->find($user, $id);

        if (!$notification) {
            return false;
        }

        $notification->delete();
        $this->announce($user, [$id]);

        return true;
    }

    private function find(User $user, string $id): ?DatabaseNotification
    {
        return Str::isUuid($id) ? $user->notifications()->whereKey($id)->first() : null;
    }

    private function announce(User $user, ?array $ids): void
    {
        event(new NotificationsReadEvent($user->id, $ids, $this->unreadCount($user)));
    }
}
