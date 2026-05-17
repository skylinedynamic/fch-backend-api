<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerPaymentLinkMail extends Mailable
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
        $subject = $this->mailData['subject'] ?? "Complete Your Account Setup - {$siteName}";

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
            view: 'emails.borrower.payment_link',
            with: array_merge([
                'user' => $user,
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? 0,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
