<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountCreateNotificationMail extends Mailable
{
    protected array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailData['subject'] 
                ?? 'Welcome to ' . ($this->mailData['business_info']['business_name'] ?? 'Our Company'),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Dynamically set login URL based on user_type
        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN => config('app.frontend_admin_url') . '/login',
            \App\Models\User\User::PARTNER => config('app.frontend_partner_url') . '/login',
            \App\Models\User\User::BORROWER => config('app.frontend_borrower_url') . '/login',
            default => config('app.url') . '/login',
        };

        return new Content(
            view: 'emails.account_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl, // 👈 add this for the Blade view
                'business_info' => [
                    'business_name'  => $this->mailData['business_info']['business_name'] ?? '',
                    'business_email' => $this->mailData['business_info']['business_email'] ?? '',
                    'business_phone' => $this->mailData['business_info']['business_phone'] ?? '',
                    'business_logo'  => app()->environment('local')
                        ? $this->mailData['business_info']['business_logo_path'] // local
                        : $this->mailData['business_info']['business_logo'],      // production
                    'address_line_1' => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
