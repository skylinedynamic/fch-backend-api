<?php

namespace App\Services\Global\Notification\Channels;

use App\Models\User\User;
use App\Services\Global\Notification\Types\NotificationTypeInterface;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;
use Exception;

class SmsChannel
{
    protected $twilio;

    public function __construct()
    {
        $sid = config('TWILIO_SID');
        $token = config('TWILIO_AUTH_TOKEN');
        
        if ($sid && $token) {
            $this->twilio = new Client($sid, $token);
        }
    }

    /**
     * Send SMS notification.
     */
    public function send(User $user, NotificationTypeInterface $notification): bool
    {
        if (!$this->isConfigured()) {
            Log::error('SMS channel not configured properly');
            return false;
        }

        if (!$user->phone_number) {
            Log::error('User does not have a phone number', ['user_id' => $user->id]);
            return false;
        }

        try {
            $message = $notification->getSmsMessage($user);
            
            $this->twilio->messages->create(
                $user->phone_number,
                [
                    'from' => config('TWILIO_FROM_NUMBER'),
                    'body' => $message
                ]
            );

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send SMS via Twilio', [
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
        return !empty(config('TWILIO_SID')) &&
               !empty(config('TWILIO_AUTH_TOKEN')) &&
               !empty(config('TWILIO_FROM_NUMBER'));
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
                    'from' => config('TWILIO_FROM_NUMBER'),
                    'body' => $message
                ]
            );

            return true;
        } catch (Exception $e) {
            Log::error('Failed to send test SMS', ['error' => $e->getMessage()]);
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
            'provider' => 'Twilio',
            'from_number' => config('TWILIO_FROM_NUMBER'),
            'has_credentials' => !empty(config('TWILIO_SID')) && !empty(config('TWILIO_AUTH_TOKEN')),
        ];
    }

    /**
     * Format message for SMS (ensure it's within character limits).
     */
    protected function formatMessage(string $message): string
    {
        // SMS messages should be concise, typically under 160 characters for single SMS
        if (strlen($message) > 160) {
            Log::warning('SMS message exceeds 160 characters', ['length' => strlen($message)]);
        }
        
        return $message;
    }
}