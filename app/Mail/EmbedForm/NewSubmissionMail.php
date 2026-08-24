<?php

namespace App\Mail\EmbedForm;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSubmissionMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(
        public EmbedFormSubmission $submission
    ) {
        $this->mailPartnerId = $submission->partner_id;
        $this->dynamicData['partner_id'] = $submission->partner_id;
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: "New Loan Application — {$this->submission->first_name} {$this->submission->last_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.embed_form.new_submission',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'submission' => $this->submission,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
