<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChangeRequestStatusMail extends Mailable
{
    use MailableBranding, MailConfig;

    public array $mailData;
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
            subject: $this->mailData['subject'] ?? 'Profile Change Request Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.change_request_status',
            with: [
                'borrowerName' => $this->mailData['borrower_name'],
                'fieldName' => $this->mailData['field_name'],
                'status' => $this->mailData['status'],
                'newValue' => $this->mailData['new_value'],
                'note' => $this->mailData['note'] ?? null,
                'loginUrl' => config('app.frontend_borrower_url') . '/login',
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
