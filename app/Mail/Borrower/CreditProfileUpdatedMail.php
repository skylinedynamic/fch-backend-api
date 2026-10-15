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
 * Tells a borrower their credit report has been saved to their profile.
 *
 * Sent when the host saves the iSoftPull data on Client -> Credit Profile.
 * Queued, like the request mail it answers, so a slow mail server never holds
 * up the host's save. From the borrower's own partner's mailbox and branding,
 * with a link to their own portal (a white-label partner's borrower domain,
 * else the shared borrower portal).
 */
class CreditProfileUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(public string $borrowerName, public ?int $borrowerPartnerId = null)
    {
        $this->setMailPartnerId($borrowerPartnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(subject: __('credit_profile_request.updated_mail_subject'));
    }

    public function content(): Content
    {
        $profileUrl = concatUrl(
            $this->borrowerPartnerId ? getBorrowerDomain($this->borrowerPartnerId) : null,
            'profile?tab=3',
            config('app.frontend_borrower_url')
        );

        return new Content(
            view: 'emails.borrower.credit_profile_updated',
            with: [
                'borrowerName' => $this->borrowerName,
                'profileUrl' => $profileUrl,
                'siteName' => $this->dynamicData['siteName'] ?? null,
                'logoUrl' => $this->dynamicData['logoUrl'] ?? null,
            ],
        );
    }
}
