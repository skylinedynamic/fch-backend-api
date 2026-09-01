<?php

namespace App\Mail\Borrower;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\CreditProfileRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UpdateCreditProfileRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $creditRequest;
    protected array $dynamicData = [];

    public function __construct(CreditProfileRequest $creditRequest, ?int $partnerId = null)
    {
        $this->creditRequest = $creditRequest;
        $this->setMailPartnerId($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'Request to update credit profile data',
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_partner_url'), '/');

        return new Content(
            view: 'emails.borrower.update_credit_profile_request',
            with: [
                'creditRequest' => $this->creditRequest,
                'baseUrl' => $baseUrl,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }
}
