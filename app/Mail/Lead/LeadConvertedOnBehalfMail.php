<?php

namespace App\Mail\Lead;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a partner the host converted its lead to a borrower on its behalf.
 * Sent from - and branded as - the host.
 */
class LeadConvertedOnBehalfMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    public function __construct(
        public Lead $lead,
        public Borrower $borrower,
        public Partner $host,
        public Partner $partner,
    ) {
        $this->mailPartnerId = $host->id;
        $this->dynamicData['partner_id'] = $host->id;
    }

    /** The partner this mail is sent and branded as - the host. */
    public function mailPartnerId(): ?int
    {
        return $this->mailPartnerId;
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __('lead.converted_on_behalf_mail_subject', ['name' => $this->lead->contact_person ?: $this->lead->company_name]),
        );
    }

    public function content(): Content
    {
        $branding = $this->getBrandingData();

        return new Content(
            view: 'emails.lead.converted_on_behalf',
            with: [
                'siteName' => $branding['siteName'],
                'logoUrl' => $branding['logoUrl'],
                'lead' => $this->lead,
                'hostName' => $this->host->company_name ?: $this->host->full_name,
                'partnerName' => $this->partner->full_name ?: $this->partner->company_name,
                'leadName' => $this->lead->contact_person ?: $this->lead->company_name,
                'ownerName' => $this->lead->salesperson?->name,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
