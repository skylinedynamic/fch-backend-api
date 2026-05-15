<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

class AccountCreateNotificationMail extends Mailable
{
    use MailableBranding;

    protected array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;

        // Resolve config based on partner_id
        $this->resolveMailConfig($mailData['partner_id'] ?? null);
    }

    public function envelope(): Envelope
    {
        $siteName = config('app.name') ?? 'Four Corner Funding';
        if ($this->resolvedMailConfig && $this->resolvedMailConfig->site_name) {
            $siteName = $this->resolvedMailConfig->site_name;
        }

        $defaultSubject = "Welcome to {$siteName}";
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

        $loginUrl = match ($user->user_type) {
            \App\Models\User\User::ADMIN,
            \App\Models\User\User::PARTNER  => config('app.frontend_partner_url') . '/login',
            \App\Models\User\User::BORROWER => config('app.frontend_borrower_url') . '/login',
            default                         => config('app.url') . '/login',
        };

        return new Content(
            view: 'emails.account_created',
            with: array_merge([
                'user'          => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl'      => $loginUrl,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
