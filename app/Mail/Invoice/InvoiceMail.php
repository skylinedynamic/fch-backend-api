<?php

namespace App\Mail\Invoice;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(public $invoice, public $pdfContent = null)
    {
        $this->setMailPartnerId();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

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
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'invoice' => $this->invoice,
            ],
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
                \Illuminate\Mail\Mailables\Attachment::fromData(fn() => $this->pdfContent, "Invoice-{$this->invoice->invoice_id}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
