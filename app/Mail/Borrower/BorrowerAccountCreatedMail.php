<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerAccountCreatedMail extends Mailable
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
        $subject = $this->mailData['subject'] ?? "Welcome to {$siteName}";

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

        // ✅ Determine login URL dynamically - specific for borrower
        $loginUrl = config('app.frontend_borrower_url') . '/login';

        return new Content(
            view: 'emails.borrower.account_created',
            with: array_merge([
                'user' => $user,
                'plainPassword' => $this->mailData['plain_password'] ?? null,
                'loginUrl' => $loginUrl,
                'paymentLink' => $this->mailData['payment_link'] ?? null,
                'amount' => $this->mailData['amount'] ?? null,
                'is_update' => $this->mailData['is_update'] ?? false,
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
