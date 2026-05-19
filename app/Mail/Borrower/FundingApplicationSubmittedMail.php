<?php

namespace App\Mail\Borrower;

use App\Models\Borrower\FundingApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FundingApplicationSubmittedMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $application;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(FundingApplication $application)
    {
        $this->application = $application;
        $this->setMailPartnerId();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'New Funding Application Submitted - ' . ($this->application->business_name ?? 'Client'),
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
