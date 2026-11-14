<?php

namespace App\Mail\Borrower;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the borrower a document they uploaded was rejected, and why (BUG-087):
 * the rejection modal promises the reason is shared with them.
 *
 * Queued, and the payload is plain strings, like BorrowerVerificationStatusMail.
 * Sent from the BORROWER's partner - the brand they know - which has to be
 * passed in: the reviewer is the authenticated user when this is built.
 */
class BorrowerDocumentReviewedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->setMailPartnerId($partnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __('borrower.document_reviewed_mail.subject', ['status' => $this->mailData['status']]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.document_reviewed',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'borrowerName' => $this->mailData['borrower_name'],
                'documentName' => $this->mailData['document_name'],
                'status' => $this->mailData['status'],
                'reason' => $this->mailData['reason'] ?? null,
                // The client's own partner's portal, as the branding (R3-BUG-013) -
                // never the signed-in user's: nobody, in the queue worker. No
                // fallback: a partner with no client portal gets no button.
                'documentsUrl' => concatUrl(getBorrowerDomain($this->mailPartnerId), 'documents'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
