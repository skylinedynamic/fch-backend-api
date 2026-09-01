<?php

namespace App\Mail\Lead;

use App\Models\Lead\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class LeadNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    public $lead;
    public $details;
    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(Lead $lead, $details = null)
    {
        $this->lead = $lead;
        $this->details = (object) ($details ?? []);
        $this->setMailPartnerId();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: 'New Lead Created',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $isWebsite = ($this->lead->lead_source === Lead::LEAD_SOURCE_WEBSITE);
        $type = $this->details->type ?? ($isWebsite ? 'website' : 'manual');

        return new Content(
            view: 'emails.lead.notification',
            with: [
                'lead' => $this->lead,
                'type' => $type,
                'details' => $this->details,
                'is_pre_qualification' => (bool) ($this->details->is_pre_qualification ?? false),
                'prequalification' => $this->details->prequalification ?? null,
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'leadUrl' => concatUrl(getPartnerDomain($this->mailPartnerId), 'leads'),
            ],
        );
    }
}
