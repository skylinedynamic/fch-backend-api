<?php

namespace App\Services\Global\Notification\Mail;

use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GenericNotificationMail extends Mailable
{
    use MailableBranding;


    protected $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
        $this->resolveMailConfig($mailData['partner_id'] ?? null);
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
            subject: $this->getDynamicSubject($this->mailData['subject']),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.generic-notification',
            with: array_merge([

                'content' => $this->mailData['content'],
                'user' => $this->mailData['user'],
                'notificationType' => $this->mailData['notification_type'],
                'data' => $this->mailData['data']
            
            ], $this->getBrandingData()),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}




