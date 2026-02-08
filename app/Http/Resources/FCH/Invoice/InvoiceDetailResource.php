<?php

namespace App\Http\Resources\FCH\Invoice;

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
            'payment_date' => $this->payment_date ? Carbon::parse($this->payment_date)->format('Y-m-d') : null,
            'status' => $this->status,
            'details' => $this->details,
            'enrollment_type' => $this->subscription?->enrollment_type,
            'monthly_platform_fee' => $this->subscription?->monthly_platform_fee,
            'payable_amount_sub' => $this->subscription?->payable_amount, // Renamed to avoid collision with invoice amount
            'name' => $this->borrower
                ? ($this->borrower->first_name . ' ' . $this->borrower->last_name)
                : ($this->partner?->full_name ?? $this->partner?->company_name),
            'email' => $this->borrower?->email ?? $this->partner?->email,
            'phone' => $this->borrower?->phone_number ?? $this->partner?->phone_number,
            'address' => $this->borrower
                ? implode(', ', array_filter([$this->borrower->business_address_line_1, $this->borrower->business_city, $this->borrower->business_state, $this->borrower->business_postal_code]))
                : ($this->partner ? implode(', ', array_filter([$this->partner->address_line_1, $this->partner->city, $this->partner->state, $this->partner->postal_code])) : null),
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d') : null,
            // Add any other fields or relations if needed
        ];
    }
}
