<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = $this->getFrontendUrlByUserType($notifiable->user_type);
        $resetUrl = $frontendUrl . '/password/reset?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        // Debug logging
        \Log::info('Password reset notification', [
            'user_type' => $notifiable->user_type,
            'frontend_url' => $frontendUrl,
            'reset_url' => $resetUrl,
            'email' => $notifiable->email
        ]);

        return (new MailMessage)
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }

    /**
     * Get the frontend URL based on user type.
     */
    private function getFrontendUrlByUserType(string $userType): string
    {
        return match ($userType) {
            'Admin' => config('app.frontend_admin_url'),
            'Partner' => config('app.frontend_partner_url'),
            'Borrower' => config('app.frontend_borrower_url'),
            default => config('app.frontend_partner_url'), // Default fallback
        };
    }
}