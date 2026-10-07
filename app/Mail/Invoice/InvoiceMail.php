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
use Illuminate\Mail\Mailables\Attachment;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * The invoice PDF, base64-encoded. This mail is queued, the queue payload
     * is JSON, and raw PDF bytes are not valid UTF-8 - carried as-is, the push
     * failed and the email was never sent.
     */
    protected ?string $pdfBase64 = null;

    /**
     * Create a new message instance.
     */
    public function __construct(public $invoice, ?string $pdfContent = null, ?int $mailPartnerId = null, public ?string $customSubject = null)
    {
        $this->pdfBase64 = $pdfContent !== null && $pdfContent !== '' ? base64_encode($pdfContent) : null;

        if ($mailPartnerId) {
            $this->mailPartnerId = $mailPartnerId;
        } else {
            $this->setMailPartnerId();
        }
        $this->dynamicData['partner_id'] = $this->mailPartnerId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        $invoiceId = $this->invoice->invoice_id ?: $this->invoice->id;

        return new Envelope(
            subject: $this->customSubject ?: ("Invoice #{$invoiceId}"),
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
        if ($this->pdfBase64) {
            return [
                Attachment::fromData(fn() => base64_decode($this->pdfBase64), "Invoice-{$this->invoice->invoice_id}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
