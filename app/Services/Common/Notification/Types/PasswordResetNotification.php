<?php

namespace App\Services\Common\Notification\Types;

use App\Models\User\User;

class PasswordResetNotification implements NotificationTypeInterface
{
    protected $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Get the message content for SMS.
     */
    public function getSmsMessage(User $user): string
    {
        $appName = config('app.name');
        $frontendUrl = $this->getFrontendUrlByUserType($user->user_type);
        $resetUrl = $frontendUrl . '/password/reset?token=' . $this->token . '&email=' . urlencode($user->email);
        
        return "Your {$appName} password reset link: {$resetUrl}. This link expires in 60 minutes.";
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        return 'Reset Password Notification';
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $frontendUrl = $this->getFrontendUrlByUserType($user->user_type);
        $resetUrl = $frontendUrl . '/password/reset?token=' . $this->token . '&email=' . urlencode($user->email);

        return [
            'greeting' => "Hello {$user->first_name}!",
            'lines' => [
                'You are receiving this email because we received a password reset request for your account.',
                'This password reset link will expire in 60 minutes.',
                'If you did not request a password reset, no further action is required.'
            ],
            'action' => [
                'text' => 'Reset Password',
                'url' => $resetUrl
            ],
            'salutation' => "Best regards,<br>" . config('app.name') . " Team"
        ];
    }

    /**
     * Get notification type identifier.
     */
    public function getType(): string
    {
        return 'password_reset';
    }

    /**
     * Get any additional data needed for the notification.
     */
    public function getData(): array
    {
        return [
            'token' => $this->token,
            'expires_at' => now()->addMinutes(60)
        ];
    }

    /**
     * Get the frontend URL based on user type.
     */
    private function getFrontendUrlByUserType(?string $userType): string
    {
        // Handle null user type by defaulting to Partner
        if ($userType === null) {
            $userType = 'Partner';
        }
        
        return match ($userType) {
            'Admin' => config('app.frontend_admin_url'),
            'Partner' => config('app.frontend_partner_url'),
            'Borrower' => config('app.frontend_borrower_url'),
            default => config('app.frontend_partner_url'), // Default fallback
        };
    }
}