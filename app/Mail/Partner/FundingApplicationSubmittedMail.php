<?php

namespace App\Mail\Partner;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FundingApplicationSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $application;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(FundingApplication $application, $mailPartnerId = null)
    {
        $this->application = $application;
        $partner = $application->partner ?? $application->borrower?->partner;
        $this->mailPartnerId = $mailPartnerId ?: ($partner?->id ?: Partner::where('is_host', 1)->value('id'));
        $this->dynamicData['partner_id'] = $this->mailPartnerId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $this->dynamicData['applicationUrl'] = concatUrl(getPartnerDomain($this->mailPartnerId), 'intake-review/funding-application/' . $this->application->id, config('app.frontend_partner_url'));

        return new Envelope(
            subject: 'New Funding Application Submitted - ' . ($this->application->business_name ?? $this->application->borrower?->business_name ?? 'Client'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.partner.funding_application_submitted',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'applicationUrl' => $this->dynamicData['applicationUrl'],
                'application' => $this->application,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
