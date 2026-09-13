<?php

namespace App\Mail\EmbedForm;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\FundingApplication;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an embedded form submission is converted to a client.
 *
 * One template, two audiences: `$forStaff` picks between the FCH Admin / WLP
 * wording and the applicant's. The applicant otherwise sees nothing between
 * submitting the form and being contacted, and staff would have to notice the
 * new client themselves.
 */
class EmbedFormConverted extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(
        public EmbedFormSubmission $submission,
        public FundingApplication $application,
        public bool $forStaff = false
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
            subject: $this->forStaff
                ? __('embed_form.converted_staff_mail_subject', [
                    'name' => trim("{$this->submission->first_name} {$this->submission->last_name}"),
                ])
                : __('embed_form.converted_mail_subject', [
                    'site' => $this->dynamicData['siteName'],
                ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.embed_form.converted',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'submission' => $this->submission,
                'application' => $this->application,
                'forStaff' => $this->forStaff,
                'loginUrl' => $this->forStaff ? null : getBorrowerDomain($this->submission->partner_id),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
