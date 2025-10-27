<?php

namespace App\Services\Common\Notification\Channels;

use App\Models\User\User;
use App\Services\Common\Notification\Types\NotificationTypeInterface;
use App\Services\Common\Notification\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class EmailChannel
{
    /**
     * Send email notification.
     */
    public function send(User $user, NotificationTypeInterface $notification): bool
    {
        if (!$this->isConfigured()) {
            Log::error('Email channel not configured properly');
            return false;
        }

        try {
            $mailData = [
                'subject' => $notification->getEmailSubject($user),
                'content' => $notification->getEmailContent($user),
                'user' => $user,
                'notification_type' => $notification->getType(),
                'data' => $notification->getData()
            ];

            Log::info('...............Email notification sent.............', [
                'user_id' => $user->id,
                'notification_type' => $notification->getType()
            ]);

            Mail::to($user->email)->send(new GenericNotificationMail($mailData));

             Log::info('...............Email notification After Sent.............', [
                'user_id' => $user->id,
                'notification_type' => $notification->getType()
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if email service is configured.
     */
    public function isConfigured(): bool
    {
        $mailDriver = config('mail.default');
        
        if ($mailDriver === 'smtp') {
            return !empty(config('mail.mailers.smtp.host')) && 
                   !empty(config('mail.mailers.smtp.username'));
        }
        
        if ($mailDriver === 'mailgun') {
            return !empty(config('services.mailgun.domain')) && 
                   !empty(config('services.mailgun.secret'));
        }
        
        if ($mailDriver === 'ses') {
            return !empty(config('services.ses.key')) && 
                   !empty(config('services.ses.secret'));
        }

        if ($mailDriver === 'postmark') {
            return !empty(config('services.postmark.token'));
        }

        if ($mailDriver === 'resend') {
            return !empty(config('services.resend.key'));
        }
        
        // For other drivers or local testing
        return true;
    }

    /**
     * Send a test email to verify configuration.
     */
    public function sendTestEmail(User $user, string $message): bool
    {
        try {
            $mailData = [
                'subject' => 'Test Email - Configuration Verification',
                'content' => [
                    'greeting' => "Hello {$user->first_name}!",
                    'lines' => [
                        'This is a test email to verify your email configuration.',
                        $message,
                        'If you received this email, your email service is working correctly.'
                    ],
                    'action' => null,
                    'salutation' => 'Best regards,<br>' . config('app.name') . ' Team'
                ],
                'user' => $user,
                'notification_type' => 'test',
                'data' => []
            ];

            Mail::to($user->email)->send(new GenericNotificationMail($mailData));
            return true;
        } catch (Exception $e) {
            Log::error('Failed to send test email', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get email service status and configuration.
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'driver' => config('mail.default'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];
    }

    /**
     * Validate email address format.
     */
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Get supported email drivers.
     */
    public function getSupportedDrivers(): array
    {
        return [
            'smtp' => 'SMTP',
            'mailgun' => 'Mailgun',
            'ses' => 'Amazon SES',
            'postmark' => 'Postmark',
            'resend' => 'Resend',
            'log' => 'Log (Development)',
            'array' => 'Array (Testing)'
        ];
    }
}