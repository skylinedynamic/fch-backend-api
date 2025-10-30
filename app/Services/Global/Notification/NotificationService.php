<?php

namespace App\Services\Global\Notification;

use App\Models\User\User;
use App\Services\Global\Notification\Channels\SmsChannel;
use App\Services\Global\Notification\Channels\EmailChannel;
use App\Services\Global\Notification\Types\NotificationTypeInterface;
use Illuminate\Support\Facades\Log;
use App\Services\Global\Notification\Types\TestNotification;
use Exception;

class NotificationService
{
    protected $smsChannel;
    protected $emailChannel;

    public function __construct(SmsChannel $smsChannel, EmailChannel $emailChannel)
    {
        $this->smsChannel = $smsChannel;
        $this->emailChannel = $emailChannel;
    }

    /**
     * Send notification via SMS.
     */
    public function sendSms(User $user, NotificationTypeInterface $notification): bool
    {
        try {
            if (!$this->smsChannel->isConfigured()) {
                Log::error(__("notification.sms.errors.not_configured"));
                return false;
            }

            if (!$user->phone_number) {
                Log::error(__("notification.sms.errors.no_phone"), ['user_id' => $user->id]);
                return false;
            }

            $result = $this->smsChannel->send($user, $notification);
            
            if ($result) {
                Log::info(__("notification.sms.logs.sent_success"), [
                    'user_id' => $user->id,
                    'notification_type' => get_class($notification)
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error(__("notification.sms.errors.send_failed"), [
                'user_id' => $user->id,
                'notification_type' => get_class($notification),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send notification via Email.
     */
    public function sendEmail(User $user, NotificationTypeInterface $notification): bool
    {
        try {
            Log::info(__("notification.email.logs.send_attempt"));
            if (!$this->emailChannel->isConfigured()) {
                Log::error(__("notification.email.errors.not_configured"));
                return false;
            }

            $result = $this->emailChannel->send($user, $notification);
            
            if ($result) {
                Log::info(__("notification.email.logs.sent_success"), [
                    'user_id' => $user->id,
                    'notification_type' => get_class($notification)
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error(__("notification.email.errors.send_failed"), [
                'user_id' => $user->id,
                'notification_type' => get_class($notification),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send notification via the specified channel.
     */
    public function send(User $user, NotificationTypeInterface $notification, string $channel): bool
    {
        switch ($channel) {
            case 'sms':
                return $this->sendSms($user, $notification);
            case 'email':
                return $this->sendEmail($user, $notification);
            default:
                Log::error(__("notification.invalid_channel"), ['channel' => $channel]);
                return false;
        }
    }

    /**
     * Send notification based on user's two-factor method.
     */
    public function sendTwoFactorNotification(User $user, NotificationTypeInterface $notification): bool
    {
        $twoFactorSetting = $user->twoFactorSetting;
        if (!$twoFactorSetting) {
            Log::error(__("notification.two_factor.errors.no_settings"), ['user_id' => $user->id]);
            return false;
        }

        $method = $twoFactorSetting->method;
        
        switch ($method) {
            case 'sms':
                return $this->sendSms($user, $notification);
            case 'email':
                return $this->sendEmail($user, $notification);
            case 'totp':
                // TOTP doesn't require sending notifications
                return true;
            default:
                Log::error(__("notification.two_factor.errors.invalid_method"), ['method' => $method]);
                return false;
        }
    }

    /**
     * Get channel status information.
     */
    public function getChannelStatus(): array
    {
        return [
            'sms' => $this->smsChannel->getStatus(),
            'email' => $this->emailChannel->getStatus(),
        ];
    }

    /**
     * Check if a specific channel is configured.
     */
    public function isChannelConfigured(string $channel): bool
    {
        switch ($channel) {
            case 'sms':
                return $this->smsChannel->isConfigured();
            case 'email':
                return $this->emailChannel->isConfigured();
            default:
                return false;
        }
    }

    /**
     * Send test notification to verify channel configuration.
     */
    public function sendTestNotification(User $user, string $channel, string $message): bool
    {
        $testNotification = new TestNotification($message);
        return $this->send($user, $testNotification, $channel);
    }
}