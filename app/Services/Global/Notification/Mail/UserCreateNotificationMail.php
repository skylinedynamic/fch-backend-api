<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

class UserCreateNotificationMail extends Mailable
{
    use MailableBranding;

    protected array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
        $this->resolveMailConfig($mailData['partner_id'] ?? null);
    }

    public function envelope(): Envelope
    {
        $defaultSubject = 'Welcome to ' . $this->getFromName();
        $subject = $this->mailData['subject'] ?? $defaultSubject;

        return new Envelope(
            from: new Address(
                $this->getFromAddress(),
                $this->getFromName(),
            ),
            subject: $this->getDynamicSubject($subject),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        // ✅ Determine login URL dynamically
        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN => config('app.frontend_partner_url') . '/login',
            \App\Models\User\User::PARTNER => config('app.frontend_partner_url') . '/login',
            \App\Models\User\User::BORROWER => config('app.frontend_borrower_url') . '/login',
            default => config('app.frontend_partner_url') . '/login',
        };

        return new Content(
            view: 'emails.users_created',
            with: array_merge([
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
