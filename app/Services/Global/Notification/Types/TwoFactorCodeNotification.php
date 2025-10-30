<?php

namespace App\Services\Global\Notification\Types;

use App\Models\User\User;

class TwoFactorCodeNotification implements NotificationTypeInterface
{
    protected $code;
    protected $customMessage;

    public function __construct(string $code, ?string $customMessage = null)
    {
        $this->code = $code;
        $this->customMessage = $customMessage;
    }

    /**
     * Get the message content for SMS.
     */
    public function getSmsMessage(User $user): string
    {
        $appName = config('app.name');
        $expiryMinutes = config('EMAIL_2FA_EXPIRY_MINUTES', 5);
        
        if ($this->customMessage) {
            return $this->customMessage;
        }

        return trans('auth.2fa_sms_message', [
            'appName' => $appName,
            'code' => $this->code,
            'minutes' => $expiryMinutes,
        ]);
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        $appName = config('app.name');
        return trans('auth.2fa_email_subject', [
            'appName' => $appName,
        ]);
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $appName = config('app.name');
        $expiryMinutes = config('EMAIL_2FA_EXPIRY_MINUTES', 5);
        
        $content = [
            'greeting' => trans('auth.greeting_hello_name', ['name' => $user->first_name]),
            'lines' => [
                trans('auth.2fa_email_lines.line_1')
            ],
            'salutation' => trans('auth.salutation_thank_you_regards_team', ['appName' => $appName])
        ];

        if ($this->customMessage) {
            $content['lines'][] = $this->customMessage;
        } else {
            $content['lines'][] = trans('auth.2fa_email_lines.line_2');
            $content['lines'][] = trans('auth.2fa_email_lines.line_3', ['minutes' => $expiryMinutes]);
            $content['lines'][] = trans('auth.2fa_email_lines.line_4');
        }

        return $content;
    }

    /**
     * Get notification type identifier.
     */
    public function getType(): string
    {
        return 'two_factor_code';
    }

    /**
     * Get any additional data needed for the notification.
     */
    public function getData(): array
    {
        return [
            'code' => $this->code,
            'expires_at' => now()->addMinutes(config('EMAIL_2FA_EXPIRY_MINUTES', 5)),
            'custom_message' => $this->customMessage
        ];
    }
}