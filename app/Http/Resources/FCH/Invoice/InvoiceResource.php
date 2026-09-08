<?php

namespace App\Http\Resources\FCH\Invoice;

use App\Models\Common\AppConfiguration;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
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
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'borrower_id' => $this->borrower_id,
            'partner_id' => $this->partner_id,
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
            'customer_name' => $this->customer_name,
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
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
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

        return ucwords(str_replace('_', ' ', (string) $this->type));
    }

    /**
     * Resolve branding data for the invoice with static caching to prevent N+1 queries.
     */
    protected function resolveBrandingData(): array
    {
        static $brandingCache = [];

        $partner = $this->relationLoaded('partner') && $this->partner
            ? $this->partner
            : null;

        $targetPartnerId = $this->borrower_id ? $this->partner_id : null;

        if (!$partner && $targetPartnerId) {
            $partner = Partner::find($targetPartnerId);
        }

        if (!$partner || $partner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL) {
            static $hostPartner = null;
            if ($hostPartner === null) {
                $hostPartner = Partner::where('is_host', 1)->first();
            }
            $partner = $hostPartner;
        }

        $resolvedPartnerId = $partner?->id ?? 0;

        if (!isset($brandingCache[$resolvedPartnerId])) {
            $branding = $resolvedPartnerId
                ? AppConfiguration::where('partner_id', $resolvedPartnerId)
                    ->where('module', AppConfiguration::MODULE_BRANDING)
                    ->pluck('value', 'key')
                    ->toArray()
                : [];

            $siteName = $branding['site_name']
                ?? $partner?->company_name
                ?? config('app.name');

            $rawLogo = $branding['primary_logo']
                ?? $branding['secondary_logo']
                ?? $branding['header_logo']
                ?? $partner?->company_logo;

            $logoUrl = asset('logo-mail.png');

            if ($rawLogo && $rawLogo !== 'null') {
                if (str_starts_with($rawLogo, 'http')) {
                    $logoUrl = encodeUrl($rawLogo);
                } else {
                    $cleanPath = ltrim((string) $rawLogo, '/');

                    if (!str_starts_with($cleanPath, 'site-settings/') && !str_starts_with($cleanPath, 'partners/')) {
                        $cleanPath = ($partner && $partner->company_logo === $rawLogo)
                            ? "partners/{$resolvedPartnerId}/{$cleanPath}"
                            : "site-settings/{$cleanPath}";
                    }

                    $logoUrl = encodeUrl(asset('storage/' . $cleanPath));
                }
            }

            $brandingCache[$resolvedPartnerId] = [
                'site_name' => $siteName,
                'primary_logo' => $logoUrl,
                'logo' => $logoUrl,
                'address' => $partner?->address_line_1 ?? '',
                'city' => $partner?->city ?? '',
                'state' => $partner?->state ?? '',
                'postal_code' => $partner?->postal_code ?? '',
                'country' => $partner?->country ?? '',
            ];
        }

        return $brandingCache[$resolvedPartnerId];
    }
}
