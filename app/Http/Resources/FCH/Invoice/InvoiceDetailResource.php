<?php

namespace App\Http\Resources\FCH\Invoice;

use App\Models\Invoice\Invoice;
use Illuminate\Http\Request;

class InvoiceDetailResource extends InvoiceResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;
        $borrower = $this->relationLoaded('borrower') ? $this->borrower : null;
        $partner = $this->relationLoaded('partner') ? $this->partner : null;

        $isCommissionPayout = $this->type === Invoice::INVOICE_TYPE_COMMISSION_PAYOUT;

        $detailFields = [
            'note' => $this->note,
            'details' => $this->details,
            'enrollment_type' => $isCommissionPayout
                ? ucwords(str_replace('_', ' ', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT))
                : $subscription?->enrollment_type,
            'monthly_platform_fee' => $isCommissionPayout
                ? null
                : $subscription?->monthly_platform_fee,
            'payable_amount_sub' => $isCommissionPayout
                ? $this->payment_amount
                : $subscription?->payable_amount,

            // Consolidated Billing Info (Borrower priority for Preview)
            'name' => $borrower
                ? $borrower->full_name
                : ($partner?->full_name ?? $partner?->company_name),
            'phone' => $borrower?->phone_number ?? $partner?->phone_number,
            'address' => $borrower
                ? ($borrower->business_address_line_1 ?: $borrower->business_postal_code)
                : ($partner ? ($partner->address_line_1 ?: $partner->postal_code) : null),
        ];

        $typePos = array_search('type', array_keys($data), true);

        if ($typePos !== false) {
            $before = array_slice($data, 0, $typePos + 1, true);
            $after = array_slice($data, $typePos + 1, null, true);

            // Remove parent 'details' from $after since $detailFields provides it in exact order
            unset($after['details']);

            return $before + $detailFields + $after;
        }

        return array_merge($data, $detailFields);
    }
}
