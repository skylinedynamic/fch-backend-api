<?php

namespace App\Mail\Invoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding;

    /**
     * Create a new message instance.
     */
    public function __construct(public $invoice, public $pdfContent = null) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice #' . $this->invoice->invoice_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice.sent',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdfContent) {
            return [
                \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $this->pdfContent, "Invoice-{$this->invoice->invoice_id}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
