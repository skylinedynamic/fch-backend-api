<?php

namespace App\Notifications;

use App\Services\FCH\Common\Notification\RenderedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * The single notification class behind Notifier: what it says comes from the
 * type's templates, which channels it uses from the type's configuration.
 *
 * Broadcast: Laravel's BroadcastNotificationCreated event on the user's
 * private channel (User::receivesBroadcastNotificationsOn), which Echo's
 * `.notification()` listens for. The payload is the stored notification
 * itself, with `type` set to the type key.
 */
class DynamicNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param string[] $channels Channel names from config('notifications.channels')
     */
    public function __construct(
        public string $typeKey,
        public RenderedNotification $message,
        public array $channels,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $map = config('notifications.channels', []);

        return array_values(array_filter(array_map(fn (string $channel) => $map[$channel] ?? null, $this->channels)));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->typeKey;
    }

    public function toArray(object $notifiable): array
    {
        return $this->message->toArray();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->message->toArray() + [
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function toSms(object $notifiable): string
    {
        return $this->message->sms;
    }

    public function broadcastType(): string
    {
        return $this->typeKey;
    }
}
