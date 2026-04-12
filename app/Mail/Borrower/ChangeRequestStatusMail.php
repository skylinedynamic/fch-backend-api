<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChangeRequestStatusMail extends Mailable
{
    public array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailData['subject'] ?? 'Profile Change Request Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.change_request_status',
            with: [
                'borrowerName' => $this->mailData['borrower_name'],
                'fieldName'    => $this->mailData['field_name'],
                'status'       => $this->mailData['status'],
                'newValue'     => $this->mailData['new_value'],
                'note'         => $this->mailData['note'] ?? null,
                'loginUrl'     => config('app.frontend_borrower_url') . '/login',
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
