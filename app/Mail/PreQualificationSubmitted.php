<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Borrower\PreQualificationRequest;

class PreQualificationSubmitted extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public $preQualification;

    /**
     * Create a new message instance.
     */
    public function __construct(PreQualificationRequest $preQualification)
    {
        $this->preQualification = $preQualification;
        $this->resolveMailConfig($this->preQualification->partner_id ?? null);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'New Pre-Qualification Application Submitted';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject($subject),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.pre_qualification_submitted',
            with: $this->getBrandingData(),
        );
    }
}
