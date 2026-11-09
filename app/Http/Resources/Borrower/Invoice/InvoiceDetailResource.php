<?php

namespace App\Http\Resources\Borrower\Invoice;

use App\Services\FCH\Common\Invoice\InvoicePartyResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The invoice detail payload read by the borrower portal's invoice page and
 * preview modal - only the fields those screens use.
 *
 * Every figure is the invoice's own. The subscription is read for its plan
 * name only, and only on an invoice that bills for that plan.
 */
class InvoiceDetailResource extends JsonResource
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
            'displayable_amount' => currencyFormat($this->payment_amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'payment_date' => toDateOnly($this->payment_date),
            'created_at' => toDateOnly($this->created_at),
            'enrollment_type' => $this->when(
                $this->resource->billsSubscriptionPlan(),
                fn() => $this->subscription?->enrollment_type
            ),

            // Who issued the invoice and who pays it - see InvoicePartyResolver.
            ...app(InvoicePartyResolver::class)->resolve($this->resource),

            'branding' => $this->resolveBrandingData(),
        ];
    }

    /**
     * Get human readable formatted invoice type.
     */
    protected function getFormattedType(): string
    {
        if (empty($this->type)) {
            return '';
        }

        $isEnrollmentType = $this->type === \App\Models\Invoice\Invoice::INVOICE_TYPE_ENROLLMENT;
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;

        if ($isEnrollmentType && $subscription && $subscription->down_payment > 0 && (int) $subscription->installment_type === 1) {
            return ucwords(\App\Models\Invoice\Invoice::INVOICE_TYPE_ENROLLMENT) . ' (' . ucwords(str_replace('_', ' ', \App\Models\Invoice\Invoice::INVOICE_TYPE_DOWN_PAYMENT)) . ')';
        }

        return ucwords(str_replace('_', ' ', (string) $this->type));
    }

    private function resolveBrandingData()
    {
        // Resolved from the INVOICE rather than getConfigPartner(): the request
        // tenant is the portal the borrower happens to be on, which is not
        // necessarily who is being paid. invoiceBranding() applies the shared
        // rule - the recipient's brand: the host on a Partner invoice, and
        // invoices.partner_id on a Borrower one - and resolves the whole fallback
        // chain through AppConfigurationService, so a partner who only uploaded
        // under the older surface names is still matched.
        $resolved = invoiceBranding(
            $this->entity_type,
            $this->partner_id ? (int) $this->partner_id : null
        );
        $branding = $resolved['branding'];

        // Invoice paper is white, so this is the document logo, falling back to
        // the sidebar logo when this tenant uploaded no document one. The
        // issuer's address is not repeated here: billed_from carries it.
        return [
            'site_name' => $branding['site_name'] ?? '',
            'logo_document_full_url' => $resolved['logo'],
            'logo_sidebar_full_url' => $branding['logo_sidebar_full_url'] ?? '',
        ];
    }
}
