<?php

namespace App\Services\Global\Notification\Channels;

use App\Models\User\User;
use App\Services\Global\Notification\Types\NotificationTypeInterface;
use App\Services\Global\Notification\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

class EmailChannel
{
    public function __construct(
        protected EmailConfigurationService $emailConfigService
    ) {}
    /**
     * Send email notification.
     */
    public function send(User $user, NotificationTypeInterface $notification): bool
    {
        if (!$this->isConfigured()) {
            Log::error(__('notification.email.errors.not_configured'));
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

            Log::info(__('notification.email.logs.sent'), [
                'user_id' => $user->id,
                'notification_type' => $notification->getType()
            ]);

            $restore = $this->emailConfigService->applyToMailer($user->partner_id);
            try {
                Mail::to($user->email)->send(new GenericNotificationMail($mailData));
            } finally {
                $restore();
            }

            Log::info(__('notification.email.logs.after_sent'), [
                'user_id' => $user->id,
                'notification_type' => $notification->getType()
            ]);

            return true;

        } catch (Exception $e) {
            Log::error(__('notification.email.errors.send_failed'), [
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
                'subject' => trans('notification.test_email.subject'),
                'content' => [
                    'greeting' => trans('notification.greeting_hello_name', ['name' => $user->first_name]),
                    'lines' => [
                        $message,
                    ],
                    'action' => null,
                    'salutation' => trans('notification.salutation_best_regards_team', [
                        'appName' => config('app.name')
                    ])
                ],
                'user' => $user,
                'notification_type' => 'test',
                'data' => []
            ];
            
            $restore = $this->emailConfigService->applyToMailer($user->partner_id);
            try {
                Mail::to($user->email)->send(new GenericNotificationMail($mailData));
            } finally {
                $restore();
            }
            return true;
        } catch (Exception $e) {
            Log::error(__('notification.email.errors.test_send_failed'), ['error' => $e->getMessage()]);
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
            'smtp' => trans('notification.email.drivers.smtp'),
            'mailgun' => trans('notification.email.drivers.mailgun'),
            'ses' => trans('notification.email.drivers.ses'),
            'postmark' => trans('notification.email.drivers.postmark'),
            'resend' => trans('notification.email.drivers.resend'),
            'log' => trans('notification.email.drivers.log'),
            'array' => trans('notification.email.drivers.array')
        ];
    }
}