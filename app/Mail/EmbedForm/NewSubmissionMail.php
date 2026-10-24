<?php

namespace App\Mail\EmbedForm;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSubmissionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param  bool  $hostCopy  The host's copy of a partner's application: sent
     *                          under the host's own mailbox and branding, and
     *                          naming the partner whose form it came through.
     */
    public function __construct(
        public EmbedFormSubmission $submission,
        public bool $hostCopy = false,
        public ?int $hostPartnerId = null,
        public ?string $formPartnerName = null,
    ) {
        $senderId = $hostCopy && $hostPartnerId ? $hostPartnerId : $submission->partner_id;
        $this->mailPartnerId = $senderId;
        $this->dynamicData['partner_id'] = $senderId;
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __('embed_form.new_submission_mail_subject', [
                'name' => "{$this->submission->first_name} {$this->submission->last_name}",
            ]),
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
                'hostCopy' => $this->hostCopy,
                'formPartnerName' => $this->formPartnerName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
