<?php

namespace App\Mail\Lead;

use App\Models\Lead\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class LeadNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $lead;
    public $details;

    /**
     * Create a new message instance.
     */
    public function __construct(Lead $lead, $details = null)
    {
        $this->lead = $lead;
        $this->details = (object) ($details ?? []);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'New Lead Created';

        return new Envelope(
            subject: $subject,
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
            ],
        );
    }
}
