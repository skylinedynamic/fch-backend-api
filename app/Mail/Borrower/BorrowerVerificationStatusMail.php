<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerVerificationStatusMail extends Mailable
{
    use MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->setMailPartnerId();
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: $this->mailData['subject'] ?? 'Verification Status Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.verification_status',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'borrowerName' => $this->mailData['borrower_name'],
                'status' => $this->mailData['status'],
                'note' => $this->mailData['note'] ?? null,
                'taskResults' => $this->mailData['task_results'],
                'loginUrl' => config('app.frontend_borrower_url') . '/login',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
