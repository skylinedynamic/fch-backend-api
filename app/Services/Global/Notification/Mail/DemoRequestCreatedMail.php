<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\DemoRequest\DemoRequest;

class DemoRequestCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public DemoRequest $demoRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(DemoRequest $demoRequest)
    {
        $this->demoRequest = $demoRequest;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject('New Demo Request Created')
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
