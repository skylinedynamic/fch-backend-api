<?php

namespace App\Http\Resources\FCH\Invoice;

use App\Models\Invoice\Invoice;
use App\Services\FCH\Common\Invoice\InvoicePartyResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The person paying and their company, read live from partners/borrowers.
        // invoices.customer_name is a creation-time snapshot that some writers fill
        // with the partner's company, so it is only the fallback for a payer that
        // no longer exists.
        $payer = app(InvoicePartyResolver::class)->billedTo($this->resource);

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'borrower_id' => $this->borrower_id,
            'partner_id' => $this->partner_id,
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
            'customer_name' => $payer['name'] ?? $this->customer_name,
            'company_name' => $payer['company_name'],
            'email' => $this->email,
            'country' => $this->country,
            'payment_amount' => (float) $this->payment_amount,
            'displayable_payment_amount' => currencyFormat(
                $this->payment_amount,
                2,
                $this->currency_symbol ?? '$',
                $this->conversion_rate ?? 1
            ),
            'payment_date' => toDateOnly($this->payment_date),
            'status' => $this->status,
            'payment_id' => $this->relationLoaded('latestPayment')
                ? $this->latestPayment?->id
                : ($this->relationLoaded('payments') ? $this->payments->first()?->id : $this->payments()->latest('id')->value('id')),
            'type' => $this->getFormattedType(),
            // Paying it reactivates a cancelled subscription (BUG-069).
            'reactivates_subscription' => $this->reactivatesSubscription(),
            'details' => $this->details,
            'branding' => $this->resolveBrandingData(),
            'can_approve' => (bool) $this->canApproveForUser($request->user()),
            'is_receiver' => (function () use ($request) {
                $authUser = $request->user();
                if (!$authUser) {
                    return false;
                }
                if ($authUser->is_host) {
                    return true;
                }
                $entityClass = $this->entity_type;
                $isBorrowerInvoice = ($entityClass === \App\Models\Borrower\Borrower::class || $this->entity_name === 'Borrower' || !empty($this->borrower_id));
                return $isBorrowerInvoice && ((int) $this->partner_id === (int) $authUser->partner_id);
            })(),
            // The invoice's own date, as the PDF shows it - not shifted into the viewer's zone (BUG-122).
            'created_at' => toDateOnly($this->created_at),
        ];
    }

    /**
     * Get human readable formatted invoice type safely without triggering N+1 queries.
     */
    protected function getFormattedType(): string
    {
        $isEnrollmentType = $this->type === Invoice::INVOICE_TYPE_ENROLLMENT;
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;

        if ($isEnrollmentType && $subscription && $subscription->down_payment > 0 && (int) $subscription->installment_type === 1) {
            return ucwords(Invoice::INVOICE_TYPE_ENROLLMENT) . ' (' . ucwords(str_replace('_', ' ', Invoice::INVOICE_TYPE_DOWN_PAYMENT)) . ')';
        }

        return $this->resource->typeName();
    }

    /**
     * Resolve branding data for the invoice with static caching to prevent N+1 queries.
     */
    protected function resolveBrandingData(): array
    {
        static $brandingCache = [];

        // Branding belongs to whoever is being PAID, not to the viewing tenant:
        // a Partner invoice is the host billing the partner, so it carries the
        // host's brand, while a Borrower invoice is the partner billing its own
        // client and carries that partner's. The rule lives in invoiceBranding()
        // so every invoice surface (this resource, the borrower resource and both
        // PDF builders) agrees.
        $partnerId = $this->partner_id ? (int) $this->partner_id : null;
        $entityType = $this->entity_type;

        // Cached on the same inputs the rule reads, so an invoice list resolves
        // each distinct combination once rather than once per row.
        $cacheKey = ($entityType ?? '-') . '|' . ($partnerId ?? 0);

        if (!isset($brandingCache[$cacheKey])) {
            $resolved = invoiceBranding($entityType, $partnerId);
            $partner = $resolved['partner'];
            $branding = $resolved['branding'];
            $resolvedPartnerId = $partner?->id ?? 0;

            $siteName = $branding['site_name']
                ?? $partner?->company_name
                ?? config('app.name');

            // Invoice paper is white, so the document logo comes first, falling
            // back to the sidebar logo when this tenant uploaded no document one.
            // primary_logo is deliberately not used here: it is the
            // white-on-transparent mark, which is invisible on a printed invoice.
            $logoUrl = $resolved['logo'];

            // A partner logo uploaded outside the branding module is a last resort.
            if (!$logoUrl && $partner?->company_logo && $partner->company_logo !== 'null') {
                $raw = ltrim((string) $partner->company_logo, '/');
                $logoUrl = str_starts_with($raw, 'http')
                    ? encodeUrl($raw)
                    : encodeUrl(asset('storage/partners/' . $resolvedPartnerId . '/' . $raw));
            }

            // Deliberately no bundled default: an invoice with no logo shows the
            // company name rather than another tenant's mark.

            $brandingCache[$cacheKey] = [
                'site_name' => $siteName,
                'logo_document_full_url' => $logoUrl,
                'logo_sidebar_full_url' => $branding['logo_sidebar_full_url'] ?? '',
                'primary_logo' => $logoUrl,
                'logo' => $logoUrl,
                'address' => $partner?->address_line_1 ?? '',
                'city' => $partner?->city ?? '',
                'state' => $partner?->state ?? '',
                'postal_code' => $partner?->postal_code ?? '',
                'country' => $partner?->country ?? '',
            ];
        }

        return $brandingCache[$cacheKey];
    }
}
