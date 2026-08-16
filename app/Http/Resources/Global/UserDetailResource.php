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
            'subscription_start_date' => toTimezone($subscription?->subscription_start_date),
            'subscription_end_date' => toTimezone($subscription?->subscription_end_date),
            'phase' => $subscription?->current_phase,
            'is_refresh' => (boolval($this->is_refresh)),
            'is_free_trail' => $entityIsFreeTrial || ($partner?->is_free_trail ?? false),
            'free_trail_end_date' => toTimezone($entityFreeTrialEndDate ?? ($partner?->free_trail_end_date ?? null)),
        ];
    }
}
