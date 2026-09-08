<?php

namespace App\Http\Resources\Borrower\Invoice;

use App\Models\Common\AppConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->header('x-timezone', config('app.timezone', 'UTC'));

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
            'displayable_amount' => currencyFormat($this->payment_amount, 2, $this->currency_symbol ?? '$', $this->conversion_rate ?? 1),
            'payment_date' => toDateOnly($this->payment_date),
            'status' => $this->status,
            'type' => $this->getFormattedType(),
            'note' => $this->note,
            'details' => $this->details,
            'items' => $this->items,
            'enrollment_type' => $this->type === \App\Models\Invoice\Invoice::INVOICE_TYPE_COMMISSION_PAYOUT
                ? __('invoice.commission_payout')
                : $this->subscription?->enrollment_type,
            'monthly_platform_fee' => $this->type === \App\Models\Invoice\Invoice::INVOICE_TYPE_COMMISSION_PAYOUT
                ? null
                : $this->subscription?->monthly_platform_fee,
            'payable_amount_sub' => $this->type === \App\Models\Invoice\Invoice::INVOICE_TYPE_COMMISSION_PAYOUT
                ? $this->payment_amount
                : $this->subscription?->payable_amount, // Renamed to avoid collision with invoice amount

            // Consolidated Billing Info (Borrower priority for Preview)
            'name' => $this->borrower
                ? $this->borrower->full_name
                : ($this->partner?->full_name ?? $this->partner?->company_name),
            'email' => $this->borrower?->email ?? $this->partner?->email,
            'phone' => $this->borrower?->phone_number ?? $this->partner?->phone_number,
            'address' => $this->borrower
                ? ($this->borrower->business_address_line_1 ?: $this->borrower->business_postal_code)
                : ($this->partner ? ($this->partner->address_line_1 ?: $this->partner->postal_code) : null),

            'branding' => $this->resolveBrandingData(),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
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
        $partner = getConfigPartner();
        $branding = AppConfiguration::where('partner_id', $partner->id)->where('module', AppConfiguration::MODULE_BRANDING)->pluck('value', 'key')->toArray();

        return [
            'site_name' => $branding['site_name'] ?? '',
            'primary_logo' => isset($branding['secondary_logo']) ? encodeUrl(asset('storage/' . $branding['secondary_logo'])) : '',
            'logo' => isset($branding['secondary_logo']) ? encodeUrl(asset('storage/' . $branding['secondary_logo'])) : '',
            'address' => $partner->address_line_1 ?? '',
            'city' => $partner->city ?? '',
            'state' => $partner->state ?? '',
            'postal_code' => $partner->postal_code ?? '',
            'country' => $partner->country ?? '',
        ];
    }
}
