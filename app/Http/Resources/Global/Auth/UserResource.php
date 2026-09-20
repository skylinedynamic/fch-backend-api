<?php

namespace App\Http\Resources\Global\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Log;

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

        $targetType = $this->entity_type ?: User::class;
        $targetId = $this->entity_id ?: $this->id;
        $subscription = Subscription::where('entity_type', $targetType)
            ->where('entity_id', $targetId)
            ->first();

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
            'is_host' => (bool) ($this->partner?->is_host ?? false),
            'partner_type' => $this->partner?->partner_type,
            'partner_payment_for_me' => (bool) ($this->borrower?->partner_payment_for_me ?? true),
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'subscription_start_date' => toDateOnly($subscription?->subscription_start_date),
            'subscription_end_date' => toDateOnly($subscription?->subscription_end_date), // paid through
            'first_payment_date' => toDateOnly($subscription?->first_payment_date),
            'per_seat_amount' => $subscription?->per_seat_amount ?? 49,
            'user_seats' => $subscription?->user_seats ?? 1,
            'credit_profile_updated' => (bool) ($this->borrower?->credit_profile_updated ?? false),
            'is_paid' => (bool) ($this->is_paid ?? false),
            'phase' => $subscription?->current_phase,
            'status' => $this->status,
            'image' => $this->getEntityImage(),
            'role_id' => $roleId,
            'role_name' => $roleName,
            'is_agreement_signed' => (bool) $this->entity?->has_sign ?? false,
            'is_free_trail' => (bool) ($this->entity->is_free_trail ?? false),
            'free_trail_end_date' => toDateOnly($this->entity->free_trail_end_date ?? null),
            'permissions' => $permissionNames,
        ];
    }
}
