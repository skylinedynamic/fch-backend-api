<?php

namespace App\Mail\Borrower;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Borrower\CreditProfileRequest;

class CreditProfileRequestMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $creditProfileRequest;
    protected array $dynamicData = [];

    public function __construct(CreditProfileRequest $creditProfileRequest, ?int $partnerId = null)
    {
        $this->creditProfileRequest = $creditProfileRequest;
        $this->setMailPartnerId($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'Credit profile information updated for iSoftPull data',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.credit_profile_request',
            with: [
                'request' => $this->creditProfileRequest,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }
}
