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
        return [
            'is_host' => (boolval($this->partner?->is_host)),
            'enrollment_type' => $this->partner?->subscription?->enrollment_type,
            'subscription_status' => $this->partner?->subscription?->status,
            'first_payment_date' => $this->partner?->subscription?->first_payment_date?->toDateString(),
            'platform_fee_start_date' => $this->partner?->subscription?->platform_fee_start_date?->toDateString(),
            'next_installment_date' => $this->partner?->subscription?->phases?->next_installment_date?->toDateString(),
            'next_platform_fee_date' => $this->partner?->subscription?->phases?->next_platform_fee_date?->toDateString(),
            'enrollment_completed_at' => $this->partner?->subscription?->phases?->enrollment_completed_at?->toDateString(),
            'subscription_start_date' => $this->partner?->subscription?->subscription_start_date?->toDateString(),
            'subscription_end_date' => $this->partner?->subscription?->subscription_end_date?->toDateString(),
            'phase' => $this->partner?->subscription?->enrollmentPhase?->phase,
            'total_paid_users' => $this->partner?->total_paid_users,
            'total_free_users' => $this->partner?->total_free_users,
            'is_refresh' => (boolval($this->is_refresh)),
            'is_free_trail' => (bool) ($this->entity?->is_free_trail ?? $this->partner?->is_free_trail ?? false),
            'free_trail_end_date' => ($this->entity?->free_trail_end_date ?? $this->partner?->free_trail_end_date ?? null)?->toDateString(),
        ];
    }
}
