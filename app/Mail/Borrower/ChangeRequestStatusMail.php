<?php

namespace App\Mail\Borrower;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChangeRequestStatusMail extends Mailable
{
    use MailableBranding;

    public array $mailData;

    public function __construct(array $mailData, ?int $partnerId = null)
    {
        $this->mailData = $mailData;
        $this->resolveMailConfig($partnerId);
    }

    public function envelope(): Envelope
    {
        $subject = $this->mailData['subject'] ?? 'Profile Change Request Update';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject($subject),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.borrower.change_request_status',
            with: array_merge([

                'borrowerName' => $this->mailData['borrower_name'],
                'fieldName'    => $this->mailData['field_name'],
                'status'       => $this->mailData['status'],
                'newValue'     => $this->mailData['new_value'],
                'note'         => $this->mailData['note'] ?? null,
                'loginUrl'     => config('app.frontend_borrower_url') . '/login',
            
            ], $this->getBrandingData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
