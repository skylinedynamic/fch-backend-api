<?php

namespace App\Http\Resources\FCH\Invoice;

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
            'displayable_payment_amount' => currencyFormat($this->payment_amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'payment_date' => toTimezone($this->payment_date),
            'status' => $this->status,
            'type' => ($this->type === Invoice::INVOICE_TYPE_ENROLLMENT && $this->relationLoaded('subscription') && $this->subscription && (int) $this->subscription->installment_type === 1) ? ucwords(Invoice::INVOICE_TYPE_ENROLLMENT) . ' (' . ucwords(str_replace('_', ' ', Invoice::INVOICE_TYPE_DOWN_PAYMENT)) . ')' : ucwords(str_replace('_', ' ', $this->type)),
            'details' => $this->details,
            // 'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'branding' => $this->resolveBrandingData(),
            'created_at' => toTimezone($this->created_at),
            // Add any other fields or relations if needed
        ];
    }

    private function resolveBrandingData()
    {
        // 1. Logic: borrower_id NULL -> Host Branding (is_host=1)
        // If borrower_id is present -> Partner Branding
        $partnerId = $this->borrower_id ? $this->partner_id : null;

        $partner = null;
        if ($partnerId) {
            $partner = \App\Models\Partner\Partner::find($partnerId);

            // Only use partner branding if it's a White-Label Partner
            if ($partner && $partner->partner_type !== \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
                $partner = null; // Fallback to host
            }
        }

        if (!$partner) {
            $partner = Partner::where('is_host', 1)->first();
        }

        $entityId = $partner->id;
        $entityType = Partner::class;

        // 2. Resolve Site Name
        $siteName = \App\Models\Common\AppConfiguration::get('site_name', null, $entityType, $entityId)
            ?? $partner->company_name
            ?? config('app.name');

        // 3. Resolve Logo - Check multiple keys, prioritize primary_logo
        $logoPath = \App\Models\Common\AppConfiguration::get('primary_logo', null, $entityType, $entityId)
            ?? \App\Models\Common\AppConfiguration::get('partner_logo', null, $entityType, $entityId)
            ?? \App\Models\Common\AppConfiguration::get('header_logo', null, $entityType, $entityId)
            ?? $partner->company_logo;

        $logoUrl = asset('logo-mail.png'); // Final fallback

        if ($logoPath && $logoPath !== 'null') {
            if (str_starts_with($logoPath, 'http')) {
                $logoUrl = $logoPath;
            } else {
                $cleanPath = ltrim((string) $logoPath, '/');

                // If it doesn't have a known prefix, try to determine it
                if (!str_starts_with($cleanPath, 'site-settings/') && !str_starts_with($cleanPath, 'partners/')) {
                    // If it's the model logo, it's in partners/{id}/
                    if ($partner->company_logo === $logoPath) {
                        $cleanPath = "partners/{$entityId}/" . $cleanPath;
                    } else {
                        // Otherwise assume it's a site setting
                        $cleanPath = "site-settings/" . $cleanPath;
                    }
                }

                $logoUrl = asset('storage/' . $cleanPath);
            }
        }

        return [
            'site_name' => $siteName,
            'primary_logo' => $logoUrl,
            'logo' => $logoUrl,
            'address' => $partner->address_line_1 ?? '',
            'city' => $partner->city ?? '',
            'state' => $partner->state ?? '',
            'postal_code' => $partner->postal_code ?? '',
            'country' => $partner->country ?? '',
        ];
    }
}
