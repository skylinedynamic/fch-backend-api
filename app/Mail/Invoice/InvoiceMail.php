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
    public function __construct(public $invoice, public $pdfContent = null)
    {
        $this->resolveMailConfig($this->invoice->partner_id);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $siteName = $branding['siteName'];
        
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->getFromAddress(),
                $this->getFromName()
            ),
            subject: $this->getDynamicSubject("Invoice #{$this->invoice->invoice_id} - {$siteName}"),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice.sent',
            with: array_merge([
                'invoice' => $this->invoice,
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
        if ($this->pdfContent) {
            return [
                \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $this->pdfContent, "Invoice-{$this->invoice->invoice_id}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
