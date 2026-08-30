<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Queue\SerializesModels;
use App\Models\DemoRequest\DemoRequest;
use Illuminate\Mail\Mailables\Envelope;

class DemoRequestCreatedMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    public DemoRequest $demoRequest;

    public function __construct(DemoRequest $demoRequest)
    {
        $this->demoRequest = $demoRequest;
        $this->resolveMailConfig(null); // Demo requests are usually global
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject('New Demo Request Created'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            view: 'emails.demo_request_created',
            with: array_merge([
                'customer_name' => $this->demoRequest->customer_name,
                'status'        => $this->demoRequest->status,
                'start_date'    => $this->demoRequest->demo_start ?? 'Not Scheduled',
                'end_date'      => $this->demoRequest->demo_end ?? 'Not Scheduled',
                'assigned_to'   => $this->demoRequest->assigned_email ?? 'Unassigned',
            ], $this->getBrandingData()),
        );
    }
}
