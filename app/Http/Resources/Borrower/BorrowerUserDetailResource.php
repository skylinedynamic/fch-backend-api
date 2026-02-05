<?php

namespace App\Http\Resources\Borrower;

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
        $phase = $subscription?->phases?->first();

        return [
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'first_payment_date' => $subscription?->first_payment_date?->toDateString(),
            'platform_fee_start_date' => $subscription?->platform_fee_start_date?->toDateString(),
            'next_installment_date' => $phase?->next_installment_date?->toDateString(),
            'next_platform_fee_date' => $phase?->next_platform_fee_date?->toDateString(),
            'enrollment_completed_at' => $phase?->enrollment_completed_at?->toDateString(),
            'subscription_start_date' => $subscription?->subscription_start_date?->toDateString(),
            'subscription_end_date' => $subscription?->subscription_end_date?->toDateString(),
        ];
    }
}
