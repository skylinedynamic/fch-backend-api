<?php

namespace App\Services\Global\Notification\Types;

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
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($user->email);
        
        return trans('notification.password_reset.sms_message', [
            'appName' => $appName,
            'resetUrl' => $resetUrl,
        ]);
    }

    /**
     * Get the email subject.
     */
    public function getEmailSubject(User $user): string
    {
        return trans('notification.password_reset.email_subject');
    }

    /**
     * Get the email content/body.
     */
    public function getEmailContent(User $user): array
    {
        $appName = config('app.name');
        $frontendUrl = $this->getFrontendUrlByUserType($user->user_type);
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($user->email);

        return [
            'greeting' => __('notification.greeting_hello_name', [
                'name' => $user->first_name,
            ]),
            'lines' => [
                __('notification.password_reset.email_lines.line_1'),
                __('notification.password_reset.email_lines.line_2'),
                __('notification.password_reset.email_lines.line_3'),
            ],
            'action' => [
                'text' => __('notification.password_reset.action_text'),
                'url' => $resetUrl,
            ],
            'salutation' => __('notification.salutation_best_regards_team', [
                'appName' => $appName,
            ]),
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
            $userType = User::PARTNER;
        }
        
        return match ($userType) {
            User::ADMIN => config('app.frontend_admin_url'),
            User::PARTNER => config('app.frontend_partner_url'),
            User::BORROWER => config('app.frontend_borrower_url'),
            default => config('app.frontend_partner_url'), // Default fallback
        };
    }
}