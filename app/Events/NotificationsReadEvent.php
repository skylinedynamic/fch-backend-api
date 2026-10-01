<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the user's other open tabs that notifications were read or removed,
 * so every bell shows the same unread count.
 */
class NotificationsReadEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param string[]|null $ids Notifications that changed; null means "all"
     */
    public function __construct(
        public int $userId,
        public ?array $ids,
        public int $unreadCount,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.read';
    }

    public function broadcastWith(): array
    {
        return [
            'ids' => $this->ids,
            'unread_count' => $this->unreadCount,
        ];
    }
}
