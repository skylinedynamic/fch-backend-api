<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Borrower\PreQualificationRequest;

class PreQualificationSubmitted extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $preQualification;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(PreQualificationRequest $preQualification)
    {
        $this->preQualification = $preQualification;
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
            subject: 'New Pre-Qualification Application Submitted',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.pre_qualification_submitted',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'preQualification' => $this->preQualification
            ],
        );
    }
}
