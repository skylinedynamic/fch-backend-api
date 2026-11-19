<?php

namespace App\Mail\Partner;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\Borrower;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To a client's partner when the client is created - +Add Client, or a lead /
 * embedded form submission converted to one (BorrowerService::store). Sent to
 * the partner's Client Notification Recipient, else its own email
 * (Partner::notificationEmail()), from the partner's own mailbox and branding.
 */
class ClientCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    /**
     * @param string $source How the client came to be: Borrower::CREATED_VIA_*
     */
    public function __construct(public Borrower $borrower, public string $source)
    {
        $this->setMailPartnerId($borrower->partner_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('borrower.client_created_mail.subject'));
    }

    public function content(): Content
    {
        $branding = $this->getBrandingData();
        $name = trim(implode(' ', array_filter([$this->borrower->first_name, $this->borrower->middle_name, $this->borrower->last_name])));

        return new Content(
            view: 'emails.partner.client_created',
            with: [
                'clientName' => $name !== '' ? $name : (string) $this->borrower->email,
                'businessName' => $this->borrower->business_name,
                'email' => $this->borrower->email,
                'phone' => $this->borrower->phone_number,
                'sourceText' => __('borrower.client_created_mail.sources.' . $this->source),
                'siteName' => $branding['siteName'],
                'logoUrl' => $branding['logoUrl'],
                'clientUrl' => concatUrl(getPartnerDomain($this->mailPartnerId), 'clients/' . $this->borrower->id),
            ],
        );
    }
}
