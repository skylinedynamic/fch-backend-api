<?php

namespace App\Http\Resources\Borrower;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User\User */
class BorrowerUserDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->entity?->subscription;
        $phase = $subscription?->phases;

        $partner = $this->borrower?->partner;
        $partnerType = $partner?->partner_type;
        $partnerInvoiceStatus = null;

        if ($partnerType === Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partnerInvoice = Invoice::where('partner_id', $partner->id)
                ->whereNull('borrower_id')
                ->where('is_partner_paying', true)
                ->orderBy('created_at', 'asc')
                ->first();
            $partnerInvoiceStatus = $partnerInvoice?->status;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_agreement_signed' => (bool)$this->borrower?->has_sign,
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'partner_type' => $partnerType,
            'partner_payment_for_me' => $this->borrower ? (bool) $this->borrower->partner_payment_for_me : true,
            'subscription_start_date' => toTimezone($subscription?->subscription_start_date),
            'subscription_end_date' => toTimezone($subscription?->subscription_end_date),
            'is_refresh' => $this->is_refresh,
            'is_free_trail' => (bool) ($this->entity?->is_free_trail ?? false),
            'free_trail_end_date' => toTimezone($this->entity?->free_trail_end_date ?? null),
        ];
    }
}
