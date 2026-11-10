<?php

namespace App\Mail\Partner;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BorrowerFeeInvoiceNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Invoice $invoice,
        public Borrower $borrower,
        public Partner $partner,
        public ?string $hostName = null,
        ?int $mailPartnerId = null
    ) {
        $this->mailPartnerId = $mailPartnerId ?: ($partner->id ?: Partner::where('is_host', 1)->value('id'));
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

        $partnerName = $this->partner->company_name ?: $this->partner->full_name;
        $invoiceNumber = $this->invoice->invoice_id ?: $this->invoice->id;
        // Named as on Plan & Fee and the invoice itself (BUG-194).
        $feeName = Invoice::typeLabel(Invoice::INVOICE_TYPE_BORROWER_FEE);

        return new Envelope(
            subject: "New {$feeName} Invoice #{$invoiceNumber} - {$partnerName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.partner.borrower_fee_invoice',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'invoice' => $this->invoice,
                'borrower' => $this->borrower,
                'partner' => $this->partner,
                'hostName' => $this->hostName ?: 'Admin',
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
        return [];
    }
}
