<?php

namespace App\Services\Common\Notification\Types;

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

        return "Your {$appName} verification code is: {$this->code}. This code expires in {$expiryMinutes} minutes.";
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        $appName = config('app.name');
        return "Your {$appName} Verification Code";
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $appName = config('app.name');
        $expiryMinutes = config('EMAIL_2FA_EXPIRY_MINUTES', 5);
        
        $content = [
            'greeting' => "Hello {$user->first_name}!",
            'lines' => [
                'You are receiving this email because a two-factor authentication code was requested for your account.'
            ],
            'salutation' => "Thank you for using our application!<br>Regards,<br>{$appName} Team"
        ];

        if ($this->customMessage) {
            $content['lines'][] = $this->customMessage;
        } else {
            $content['lines'][] = "Your verification code is displayed below:";
            $content['lines'][] = "This code will expire in {$expiryMinutes} minutes.";
            $content['lines'][] = 'If you did not request this code, please ignore this email and consider changing your password.';
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