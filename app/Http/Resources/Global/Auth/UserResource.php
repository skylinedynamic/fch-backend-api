<?php

namespace App\Http\Resources\Global\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
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

        // Determine subscription based on user type
        $subscription = null;
        if ($this->entity_type === Borrower::class) {
            if ($this->relationLoaded('entity') && $this->entity) {
                $subscription = $this->entity->relationLoaded('subscription') ? $this->entity->subscription : null;
            }
        } else {
            if ($this->relationLoaded('partner') && $this->partner) {
                $subscription = $this->partner->relationLoaded('subscription') ? $this->partner->subscription : null;
            }
        }

        $partnerInvoiceStatus = null;
        if ($this->partner?->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partnerInvoice = Invoice::where('partner_id', $this->partner_id)
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
            'partner_id' => $this->partner_id,
            'phone_number' => $this->phone_number,
            'user_type' => $this->user_type,
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
            'current_tier' => $this->entity_type == Borrower::class ? $this->entity?->current_tier : null,
            'is_host' => $this->partner?->is_host,
            'partner_type' => $this->partner?->partner_type,
            'partner_payment_for_me' => (bool) ($this->borrower?->partner_payment_for_me ?? true),
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'subscription_start_date' => toTimezone($subscription?->subscription_start_date),
            'subscription_end_date' => toTimezone($subscription?->subscription_end_date),
            'per_seat_amount' => $subscription?->per_seat_amount ?? 49,
            'credit_profile_updated' => $this->borrower?->credit_profile_updated,
            'is_paid' => (bool) $this->is_paid,
            'phase' => $subscription?->current_phase,
            'status' => $this->status,
            'image' => $this->getEntityImage(),
            'role_id' => $roleId,
            'role_name' => $roleName,
            'is_agreement_signed' => $this->entity?->has_sign ?? false,
            'is_free_trail' => (bool) ($this->entity->is_free_trail ?? false),
            'free_trail_end_date' => toTimezone($this->entity->free_trail_end_date ?? null),
            'permissions' => $permissionNames,
        ];
    }
}
