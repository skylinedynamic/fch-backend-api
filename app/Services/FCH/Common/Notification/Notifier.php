<?php

namespace App\Services\FCH\Common\Notification;

use App\Notifications\DynamicNotification;
use Illuminate\Support\Facades\Log;

/**
 * The one entry point for in-app notifications:
 *
 *     app(Notifier::class)->send('borrower.funding_request.status_changed', $borrower, [
 *         'id' => $request->id, 'status' => $request->status,
 *     ]);
 *
 * Types live in config/notifications.php (overridable per key in the
 * notification_types table). Delivery is queued and runs after the current
 * DB transaction commits. An unknown or disabled type is logged and skipped
 * rather than thrown, so a notification can never break the business action
 * that triggered it.
 */
class Notifier
{
    public function __construct(
        private readonly NotificationTypeRegistry $types,
        private readonly RecipientResolver $recipients,
        private readonly TemplateRenderer $renderer,
    ) {
    }

    /**
     * @param mixed $to   User, entity model (Borrower, Partner...), or an array/collection of them
     * @param array $data Values for the type's {{placeholders}}
     * @return int Number of users notified
     */
    public function send(string $typeKey, mixed $to, array $data = []): int
    {
        $type = $this->types->find($typeKey);

        if (!$type) {
            Log::warning('Notifier: unknown notification type, skipped.', ['type' => $typeKey]);
            return 0;
        }

        $channels = array_values(array_intersect($type->channels, array_keys(config('notifications.channels', []))));

        if (!$type->isActive || $channels === []) {
            return 0;
        }

        $users = $this->recipients->resolve($to);

        if ($users->isEmpty()) {
            return 0;
        }

        $message = $this->renderer->render($type, $data);

        foreach ($users as $user) {
            $user->notify(new DynamicNotification($type->key, $message, $channels));
        }

        return $users->count();
    }
}
