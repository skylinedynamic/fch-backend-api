<?php

namespace App\Http\Resources\FCH\Invoice;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Common\AppConfiguration;

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
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'customer_name' => $this->customer_name,
            'email' => $this->email,
            'country' => $this->country,
            'payment_amount' => $this->payment_amount,
            'payment_date' => $this->payment_date ? Carbon::parse($this->payment_date)->setTimezone($timezone)->format('Y-m-d') : null,
            'status' => $this->status,
            'type' => $this->type,
            'note' => $this->note,
            'details' => $this->details,
            'enrollment_type' => $this->type === \App\Models\Invoice\Invoice::INVOICE_TYPE_COMMISSION_PAYOUT 
                ? 'Commission Payout' 
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
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->setTimezone($timezone)->format('Y-m-d H:i:s') : null,
            // Add any other fields or relations if needed
        ];
    }

    private function resolveBrandingData()
    {
        $partner = getConfigPartner();
        $branding = AppConfiguration::where('partner_id',$partner->id)->where('module',AppConfiguration::MODULE_BRANDING)->pluck('value','key')->toArray();

        return [
            'site_name' => $branding['site_name'],
            'primary_logo' => encodeUrl(asset('storage/'.$branding['secondary_logo'])),
            'logo' =>  encodeUrl(asset('storage/'.$branding['secondary_logo'])),
            'address' => $partner->address_line_1 ?? '',
            'city' => $partner->city ?? '',
            'state' => $partner->state ?? '',
            'postal_code' => $partner->postal_code ?? '',
            'country' => $partner->country ?? '',
        ];
    }
}
