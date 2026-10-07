<?php

namespace App\Services\FCH\Common\Notification\Sms;

use App\Notifications\DynamicNotification;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel behind the `sms` channel name in
 * config/notifications.php. Listing `sms` on a type is all it takes to text
 * that type's recipients.
 *
 * The number comes from the notifiable's routeNotificationForSms() (see User);
 * which account sends it, normalising the number and handling failure are
 * SmsSender's. A text that cannot go out is logged there and never fails the
 * queued notification, so the bell and mail for the same type are unaffected.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (!is_string($to) || $to === '' || !$notification instanceof DynamicNotification) {
            return;
        }

        $partnerId = isset($notifiable->partner_id) ? (int) $notifiable->partner_id : null;

        $this->sender->send($to, $partnerId, $notification->toSms($notifiable), ['type' => $notification->typeKey]);
    }
}
