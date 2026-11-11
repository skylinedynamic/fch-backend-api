<?php

namespace App\Http\Resources\FCH\Invoice;

use App\Services\FCH\Common\Invoice\InvoicePartyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The invoice detail payload read by the invoice page, the preview modal and
 * the seat-purchase payment flow - only the fields those screens use.
 *
 * Extends the list resource for its type label and branding lookup only. The
 * list's own row fields (payment_id, can_approve, is_receiver, ...) are not
 * inherited: they cost queries and no detail screen reads them.
 *
 * Every figure is the invoice's own. The subscription is read for its plan
 * name only, and only on an invoice that bills for that plan.
 */
class InvoiceDetailResource extends InvoiceResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'entity_type' => $this->entity_name,
            'type' => $this->getFormattedType(),
            // Paying it reactivates a cancelled subscription (BUG-069).
            'reactivates_subscription' => $this->reactivatesSubscription(),
            'status' => $this->status,
            'payment_amount' => (float) $this->payment_amount,
            'displayable_payment_amount' => currencyFormat(
                $this->payment_amount,
                2,
                $this->currency_symbol ?? '$',
                $this->conversion_rate ?? 1
            ),
            'payment_date' => toDateOnly($this->payment_date),
            // The invoice's own date, as the PDF shows it - not shifted into the viewer's zone (BUG-122).
            'created_at' => toDateOnly($this->created_at),
            'enrollment_type' => $this->when(
                $this->resource->billsSubscriptionPlan(),
                fn () => $this->subscription?->enrollment_type
            ),
            'note' => $this->note,
            // How it was actually paid, not always "Stripe" (BUG-206).
            ...$this->paidWithPayload(),

            // Who issued the invoice and who pays it - see InvoicePartyResolver.
            ...app(InvoicePartyResolver::class)->resolve($this->resource),

            // The issuer's address is not repeated here: billed_from carries it.
            'branding' => Arr::only($this->resolveBrandingData(), [
                'site_name',
                'logo_document_full_url',
                'logo_sidebar_full_url',
            ]),
        ];
    }

    /**
     * The invoice's payment method, the payer's reference and whether it was a
     * card, for the Payment Details block (BUG-206).
     *
     * @return array{payment_method: ?string, payment_reference: ?string, is_card_payment: bool}
     */
    protected function paidWithPayload(): array
    {
        $paidWith = $this->resource->paidWith();

        return [
            'payment_method' => $paidWith['method'],
            'payment_reference' => $paidWith['reference'],
            'is_card_payment' => $paidWith['is_card'],
        ];
    }
}
