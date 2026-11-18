<?php

namespace App\Mail\Borrower;

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
        $this->mailPartnerId = $mailPartnerId;
        if (!$this->mailPartnerId) {
            $partner = $application->partner ?? $application->borrower?->partner;
            if ($partner && $partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
                $this->mailPartnerId = $partner->id;
            } else {
                $this->setMailPartnerId();
            }
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $this->dynamicData['applicationUrl'] = concatUrl(getBorrowerDomain($this->mailPartnerId), 'fund-application/' . $this->application->funding_request_id);

        return new Envelope(
            subject: 'Funding Application Successfully Submitted',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.funding_application_submitted',
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
