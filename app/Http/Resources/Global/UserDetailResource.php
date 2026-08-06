<?php

namespace App\Http\Resources\Global;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User\User */
class UserDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $partner = $this->relationLoaded('partner') ? $this->partner : null;
        $subscription = ($partner && $partner->relationLoaded('subscription')) ? $partner->subscription : null;

        $entityIsFreeTrial = false;
        $entityFreeTrialEndDate = null;
        if ($this->relationLoaded('entity') && $this->entity) {
            $entityIsFreeTrial = (bool) $this->entity->is_free_trail;
            $entityFreeTrialEndDate = $this->entity->free_trail_end_date;
        }

        return [
            'is_host' => $partner ? (boolval($partner->is_host)) : false,
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'first_payment_date' => $subscription?->first_payment_date?->toDateString(),
            'platform_fee_start_date' => $subscription?->platform_fee_start_date?->toDateString(),
            'next_installment_date' => $subscription?->next_installment_date?->toDateString(),
            'next_platform_fee_date' => $subscription?->next_platform_fee_date?->toDateString(),
            'enrollment_completed_at' => $subscription?->enrollment_completed_at?->toDateString(),
            'subscription_start_date' => $subscription?->subscription_start_date?->toDateString(),
            'subscription_end_date' => $subscription?->subscription_end_date?->toDateString(),
            'phase' => $subscription?->current_phase,
            'user_seats' => $subscription?->user_seats ?? 3,
            'is_refresh' => (boolval($this->is_refresh)),
            'is_free_trail' => $entityIsFreeTrial || ($partner?->is_free_trail ?? false),
            'free_trail_end_date' => ($entityFreeTrialEndDate ?? ($partner?->free_trail_end_date ?? null))?->toDateString(),
        ];
    }
}
