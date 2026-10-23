<?php

namespace App\Mail\EmbedForm;

use Illuminate\Contracts\Queue\ShouldQueue;
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
class EmbedFormConverted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param  int|null  $senderPartnerId  Whose mailbox, branding and sender name
     *                                     the email leaves under. Defaults to the
     *                                     form's partner; the host's own copy
     *                                     passes the host, so it comes from the
     *                                     host company rather than the partner.
     */
    public function __construct(
        public EmbedFormSubmission $submission,
        public FundingApplication $application,
        public bool $forStaff = false,
        public ?int $senderPartnerId = null,
    ) {
        $senderId = $senderPartnerId ?? $submission->partner_id;
        $this->mailPartnerId = $senderId;
        $this->dynamicData['partner_id'] = $senderId;
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: $this->forStaff
                ? __('embed_form.converted_staff_mail_subject', [
                    'name' => personName($this->submission->first_name ?? null, $this->submission->middle_name ?? null, $this->submission->last_name ?? null),
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
                // The partner's own borrower portal, or the main one when it has
                // none - as the other borrower emails do.
                'loginUrl' => $this->forStaff ? null : concatUrl(getBorrowerDomain($this->submission->partner_id), 'login', config('app.frontend_borrower_url')),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
