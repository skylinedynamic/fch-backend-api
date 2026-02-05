<?php

namespace App\Http\Resources\Global\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin \App\Models\User\User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // Resolve role name from preloaded roles only (no lazy loads)
        $roleName = null;
        $roleId = null;
        if ($this->relationLoaded('roles')) {
            $roleName = optional($this->roles->first())->name;
            $roleId = optional($this->roles->first())->id;
        }

        // Resolve permissions from preloaded relations only (no lazy loads)
        $directPerms = $this->relationLoaded('permissions') ? $this->permissions : collect();
        $rolePerms = collect();
        if ($this->relationLoaded('roles')) {
            foreach ($this->roles as $role) {
                if ($role->relationLoaded('permissions')) {
                    $rolePerms = $rolePerms->merge($role->permissions);
                }
            }
        }
        $allPerms = $directPerms->merge($rolePerms);
        $permissionNames = $allPerms->pluck('name')
            ->unique()
            ->values();

        $subscription = $this->entity?->subscription;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'user_type' => $this->user_type,
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
            'is_host' => $this->partner?->is_host,
            'partner_type' => $this->partner?->partner_type,
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'first_payment_date' => $subscription?->first_payment_date?->toDateString(),
            'platform_fee_start_date' => $subscription?->platform_fee_start_date?->toDateString(),
            'next_installment_date' => $subscription?->enrollmentPhase?->next_installment_date?->toDateString(),
            'next_platform_fee_date' => $subscription?->platformFeePhase?->next_platform_fee_date?->toDateString(),
            'enrollment_completed_at' => $subscription?->enrollmentPhase?->enrollment_completed_at?->toDateString(),
            'subscription_start_date' => $subscription?->subscription_start_date?->toDateString(),
            'subscription_end_date' => $subscription?->subscription_end_date?->toDateString(),
            'phase' => $subscription?->enrollmentPhase?->phase,
            'status' => $this->status,
            'image' => $this->image,
            'role_id' => $roleId,
            'role_name' => $roleName,
            'permissions' => $permissionNames,
        ];
    }
}
