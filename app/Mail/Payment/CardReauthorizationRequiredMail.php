<?php

namespace App\Mail\Payment;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a payer to re-enter the card their automatic payments are charged to.
 *
 * Sent when the saved card can no longer be charged because the partner receiving
 * the money has moved between Stripe's test and live accounts. A Stripe Customer
 * and its card exist in exactly one of those accounts and cannot be moved between
 * them, so the card on file is unusable and there is nothing any retry can do
 * about it - only the payer entering it again resolves this.
 *
 * Without this message nothing tells them. The auto-charge cron would simply fail
 * twice a day, writing failed ledger rows nobody reads, until someone noticed a
 * pile of unpaid invoices at the end of the month.
 */
class CardReauthorizationRequiredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param int|null $partnerId The tenant whose mailbox and branding this is
     *                            sent under. Passed explicitly because this is
     *                            queued from a cron, where there is no
     *                            authenticated user or request Origin for
     *                            setMailPartnerId() to resolve from.
     */
    public function __construct(public array $paymentData, ?int $partnerId = null)
    {
        if ($partnerId) {
            $this->mailPartnerId = $partnerId;
        } else {
            $this->setMailPartnerId();
        }
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: "Action Required: Update Your Payment Card - " . $this->dynamicData['siteName'],
        );
    }

    public function content(): Content
    {
        $userName = $this->paymentData['user_name'] ?? 'Valued Customer';
        $firstName = explode(' ', trim($userName))[0];

        $businessInfo = [
            'company_name' => $this->dynamicData['siteName'],
            'company_logo' => $this->dynamicData['logoUrl'],
        ];

        return new Content(
            view: 'emails.payment.card_reauthorization_required',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                'amount' => $this->paymentData['amount'],
                'currency' => $this->paymentData['currency'],
                'invoice_id' => $this->paymentData['invoice_id'] ?? null,
                'card_no' => $this->paymentData['card_no'] ?? null,
                'description' => $this->paymentData['description'] ?? 'Program Payment',
                'date' => ($this->paymentData['date'] ?? now())->format('F j, Y, g:i a'),
                'portal_url' => $this->paymentData['portal_url'] ?? null,
                'user_name' => $userName,
                'first_name' => $firstName,
                'business_info' => $businessInfo,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
