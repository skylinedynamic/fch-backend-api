<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BorrowerVerificationStatusMail extends Mailable
{
    use MailableBranding;

    protected array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailData['subject'] ?? 'Verification Status Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.verification_status',
            with: array_merge([

                'borrowerName'  => $this->mailData['borrower_name'],
                'status'        => $this->mailData['status'],
                'note'          => $this->mailData['note'] ?? null,
                'taskResults'   => $this->mailData['task_results'],
                'loginUrl'      => config('app.frontend_borrower_url') . '/login',
            
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
