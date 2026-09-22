<?php

namespace App\Services\Global\Notification\Channels;

use App\Models\User\User;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Services\Global\Notification\Types\NotificationTypeInterface;
use App\Services\Global\Notification\Mail\GenericNotificationMail;
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

            try {
                Mail::to($user->email)->send(new GenericNotificationMail($mailData));
            } catch (Exception $e) {
                Log::error(__('notification.email.errors.send_failed'), ['error' => $e->getMessage()]);
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
            // Credentials are tenant data and are applied per message by
            // App\Mail\Traits\MailConfig, so config/mail.php holds none at
            // rest - reading it here would report every tenant unconfigured.
            // Ask the resolver the same question the send path asks.
            return $this->tenantMailIsUsable();
        }

        if ($mailDriver === 'mailgun') {
            return !empty(config('services.mailgun.domain')) &&
                !empty(config('services.mailgun.secret'));
        }

        if ($mailDriver === 'ses') {
            // Same as smtp above: resolved per tenant, not from config.
            return $this->tenantMailIsUsable();
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
     * Whether the current tenant has mail credentials that can actually connect.
     *
     * The same resolution the send path uses, so this cannot report "configured"
     * for a message that MailConfig would then refuse to send, or the reverse.
     */
    private function tenantMailIsUsable(): bool
    {
        $service = app(EmailConfigurationService::class);
        $partner = getConfigPartner();

        return $service->isUsable($service->resolveFor($partner?->id));
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

            try {
                Mail::to($user->email)->send(new GenericNotificationMail($mailData));
            } catch (Exception $e) {
                Log::error(__('notification.email.errors.test_send_failed'), ['error' => $e->getMessage()]);
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