<?php

namespace App\Mail\Partner;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerAccountUpdateMail extends Mailable
{
    use MailableBranding;

    protected array $mailData;

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->resolveMailConfig($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];
        $subject = $this->mailData['subject'] ?? "Welcome to {$siteName} - Partner Account Updated";

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject($subject),
        );
    }

    public function content(): Content
    {
        $user = $this->mailData['user'];

        return new Content(
            view: 'emails.partner.mail_updated',
            with: array_merge([
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'is_update' => $this->mailData['is_update'] ?? false,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
