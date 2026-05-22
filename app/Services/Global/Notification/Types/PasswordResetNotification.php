<?php

namespace App\Services\Global\Notification\Types;

use App\Models\User\User;
use Illuminate\Support\Facades\Log;
use PgSql\Lob;
use App\Mail\Traits\MailableBranding;

class PasswordResetNotification implements NotificationTypeInterface
{
    use MailableBranding;
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
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];


        $appName = $siteName;
        $frontendUrl = $this->getFrontendUrlByUserType($user->user_type, $user->partner_id);
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($user->email);

        return trans('notification.password_reset.sms_message', [
            'appName' => $appName,
            'resetUrl' => make_url($resetUrl),
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
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];
        $appName = $siteName;

        $frontendUrl = $this->getFrontendUrlByUserType($user->user_type, $user->partner_id);
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($user->email);

        return [
            'greeting' => __('notification.greeting_hello_name', [
                'name' => $user->name,
            ]),
            'lines' => [
                __('notification.password_reset.email_lines.line_1'),
                __('notification.password_reset.email_lines.line_2'),
                __('notification.password_reset.email_lines.line_3'),
            ],
            'action' => [
                'text' => __('notification.password_reset.action_text'),
                'url' => make_url($resetUrl),
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
    private function getFrontendUrlByUserType(?string $userType ,$partnerId = null): string
    {
        // Handle null user type by defaulting to Partner
        if ($userType == null) {
            $userType = User::PARTNER;
        }

        return match ($userType) {
            User::ADMIN => getPartnerDomain($partnerId),
            User::PARTNER =>getPartnerDomain($partnerId),
            User::BORROWER => getBorrowerDomain($partnerId)
        };
    }

}
