<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued, so a reviewer's request is not held open on an SMTP round trip, and so
 * a transient refusal is retried rather than swallowed - see MailConfig. The
 * payload is plain strings, so it serialises safely.
 */
class ChangeRequestStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public array $mailData;
    protected array $dynamicData = [];

    /**
     * @param  ?int  $partnerId  The BORROWER's partner - the brand they know, and
     *        whose mailbox this is sent from. It was previously accepted and then
     *        ignored in favour of setMailPartnerId() with no argument, which falls
     *        back to getConfigPartner(): that resolves the REVIEWER's partner,
     *        because a change request is decided while an admin is the
     *        authenticated user. The borrower's own partner has to be passed in.
     */
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
            subject: $this->mailData['subject'] ?? 'Profile Change Request Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.change_request_status',
            with: [
                'borrowerName' => $this->mailData['borrower_name'],
                'fieldName' => $this->mailData['field_name'],
                'status' => $this->mailData['status'],
                'newValue' => $this->mailData['new_value'],
                'note' => $this->mailData['note'] ?? null,
                // The client's own partner's portal, as the branding (R3-BUG-013).
                'loginUrl' => concatUrl(getBorrowerDomain($this->mailPartnerId), 'login'),
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
