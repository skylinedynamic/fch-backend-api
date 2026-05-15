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

    /**
     * Create a new message instance.
     */
    public function __construct(DemoRequest $demoRequest)
    {
        $this->demoRequest = $demoRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->getDynamicSubject('New Demo Request Created'),
        );
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
                    ->view('emails.demo_request_created')
                    ->with([
                        'customer_name' => $this->demoRequest->customer_name,
                        'status'        => $this->demoRequest->status,
                        'start_date'    => $this->demoRequest->demo_start ?? 'Not Scheduled',
                        'end_date'      => $this->demoRequest->demo_end ?? 'Not Scheduled',
                        'assigned_to'   => $this->demoRequest->assigned_email ?? 'Unassigned',
                    ]);
    }
}
