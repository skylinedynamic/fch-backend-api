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
 * Queued, like the partner's copy of the same news.
 *
 * A decision now sends up to two messages, and an SMTP round trip takes several
 * seconds - blocking the reviewer's request on both is the wrong trade. Queuing
 * also means a transient refusal is retried rather than swallowed; see
 * MailConfig. The payload is plain strings, so it serialises safely.
 */
class BorrowerVerificationStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $mailData;
    protected array $dynamicData = [];

    /**
     * @param  ?int  $partnerId  The BORROWER's partner - the brand they know, and
     *        whose mailbox this is sent from. It was previously accepted and then
     *        ignored in favour of setMailPartnerId() with no argument, which falls
     *        back to getConfigPartner(): that resolves the REVIEWER's partner,
     *        because a verification decision is made while an admin is the
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
            subject: $this->mailData['subject'] ?? 'Verification Status Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.verification_status',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'borrowerName' => $this->mailData['borrower_name'],
                'status' => $this->mailData['status'],
                'note' => $this->mailData['note'] ?? null,
                'taskResults' => $this->mailData['task_results'],
                // The client's own partner's portal, as the branding (R3-BUG-013).
                'loginUrl' => concatUrl(getBorrowerDomain($this->mailPartnerId), 'login'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
