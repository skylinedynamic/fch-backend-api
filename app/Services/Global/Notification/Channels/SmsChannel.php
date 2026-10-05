<?php

namespace App\Services\Global\Notification\Channels;

use App\Models\User\User;
use App\Services\FCH\Common\Notification\Sms\SmsSender;
use App\Services\FCH\Common\Setting\SmsConfigurationService;
use App\Services\Global\Notification\Types\NotificationTypeInterface;

/**
 * Direct texts outside the Notifier - two-factor codes and similar - sent
 * through Settings > SMS Configuration, the same account a notification to
 * this user would use. SmsSender normalises the number and logs, rather than
 * throws, any failure.
 */
class SmsChannel
{
    public function __construct(
        private readonly SmsSender $sender,
        private readonly SmsConfigurationService $configurations,
    ) {
    }

    public function send(User $user, NotificationTypeInterface $notification): bool
    {
        return $this->sender->send(
            $user->routeNotificationForSms(),
            $this->partnerIdOf($user),
            $notification->getSmsMessage($user),
            ['user_id' => $user->id, 'type' => $notification->getType()]
        );
    }

    /**
     * Whether this user's partner has an enabled SMS account to text from.
     */
    public function isConfiguredFor(User $user): bool
    {
        return $this->configurations->resolveFor($this->partnerIdOf($user)) !== null;
    }

    public function getStatus(User $user): array
    {
        $configuration = $this->configurations->resolveFor($this->partnerIdOf($user));

        return [
            'configured' => $configuration !== null,
            'provider' => $configuration
                ? (config('sms.providers', [])[$configuration->provider]['label'] ?? $configuration->provider)
                : null,
        ];
    }

    private function partnerIdOf(User $user): ?int
    {
        return $user->partner_id ? (int) $user->partner_id : null;
    }
}
