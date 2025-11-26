<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UserCreateNotificationMail extends Mailable
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
                ?? 'Welcome to ' . ($this->mailData['business_info']['company_name'] ?? 'Our Company'),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Determine login URL dynamically
        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN => config('app.frontend_admin_url') . '/login',
            \App\Models\User\User::PARTNER => config('app.frontend_partner_url') . '/login',
            \App\Models\User\User::BORROWER => config('app.frontend_borrower_url') . '/login',
            default => config('app.url') . '/login',
        };

        return new Content(
            view: 'emails.users_created',
            with: [
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,

                'business_info' => [
                    'company_name'   => $this->mailData['business_info']['company_name'] ?? '',
                    'email'  => $this->mailData['business_info']['email'] ?? '',
                    'phone_number'  => $this->mailData['business_info']['phone_number'] ?? '',
                    'address_line_1'  => $this->mailData['business_info']['address_line_1'] ?? '',
                ],
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
