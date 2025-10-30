<?php

namespace App\Services\Global\Notification\Channels;

use App\Models\User\User;
use App\Services\Global\Notification\Types\NotificationTypeInterface;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;
use Exception;
use Twilio\Http\GuzzleClient as TwilioGuzzleClient;
use GuzzleHttp\Client as GuzzleHttpClient;

class SmsChannel
{
    protected $twilio;

    public function __construct()
    {
        $sid = config('twillo.account_sid');
        $token = config('twillo.auth_token');
        
        if ($sid && $token) {
            $this->twilio = new Client($sid, $token, null, null, new TwilioGuzzleClient(new GuzzleHttpClient()));
        }
    }

    /**
     * Send SMS notification.
     */
    public function send(User $user, NotificationTypeInterface $notification): bool
    {
        if (!$this->isConfigured()) {
            Log::error(__("notification.sms.errors.not_configured"));
            return false;
        }

        if (!$user->phone_number) {
            Log::error(__("notification.sms.errors.no_phone"), ['user_id' => $user->id]);
            return false;
        }

        try {
            $message = $notification->getSmsMessage($user);
            
            $this->twilio->messages->create(
                $user->phone_number,
                [
                    'from' => config('twillo.from'),
                    'body' => $message
                ]
            );

            return true;

        } catch (Exception $e) {
            Log::error(__("notification.sms.errors.twilio_send_failed"), [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if SMS service is configured.
     */
    public function isConfigured(): bool
    {
        return !empty(config('twillo.enabled')) &&
               !empty(config('twillo.account_sid')) &&
               !empty(config('twillo.auth_token')) &&
               !empty(config('twillo.from'));
    }

    /**
     * Verify phone number format.
     */
    public function verifyPhoneNumber(string $phoneNumber): bool
    {
        // Basic E.164 format validation
        return preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber);
    }

    /**
     * Send a test SMS to verify configuration.
     */
    public function sendTestSms(User $user, string $message): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if (!$user->phone_number) {
            return false;
        }

        try {
            $this->twilio->messages->create(
                $user->phone_number,
                [
                    'from' => config('twillo.from'),
                    'body' => $message
                ]
            );

            return true;
        } catch (Exception $e) {
            Log::error(__("notification.sms.errors.test_send_failed"), ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get SMS service status and configuration.
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'provider' => trans('notification.sms.provider_name'),
            'from_number' => config('twillo.from'),
            'has_credentials' => !empty(config('twillo.account_sid')) && !empty(config('twillo.auth_token')),
        ];
    }

    /**
     * Format message for SMS (ensure it's within character limits).
     */
    protected function formatMessage(string $message): string
    {
        // SMS messages should be concise, typically under 160 characters for single SMS
        if (strlen($message) > 160) {
            Log::warning(__("notification.sms.warnings.message_too_long"), ['length' => strlen($message)]);
        }
        
        return $message;
    }
}