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
            $subscription = $this->entity?->subscription;
        } else {
            // For Partners, Employees, or users with null entity_type but valid partner_id
            $subscription = $this->partner?->subscription;
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
            'partner_invoice_status' => $partnerInvoiceStatus,
            'partner_payment_for_me' => (bool) ($this->borrower?->partner_payment_for_me ?? true),
            'enrollment_type' => $subscription?->enrollment_type,
            'subscription_status' => $subscription?->status,
            'first_payment_date' => $subscription?->first_payment_date?->toDateString(),
            'platform_fee_start_date' => $subscription?->platform_fee_start_date?->toDateString(),
            'next_installment_date' => $subscription?->phases?->next_installment_date?->toDateString(),
            'next_platform_fee_date' => $subscription?->phases?->next_platform_fee_date?->toDateString(),
            'enrollment_completed_at' => $subscription?->phases?->enrollment_completed_at?->toDateString(),
            'subscription_start_date' => $subscription?->subscription_start_date?->toDateString(),
            'subscription_end_date' => $subscription?->subscription_end_date?->toDateString(),
            'total_paid_users' => $this->partner?->total_paid_users,
            'total_free_users' => $this->partner?->total_free_users,
            'is_paid'           => $this->is_paid,
            'start_date'        => $this->start_date ? $this->start_date->format('Y-m-d') : null,
            'end_date'          => $this->end_date ? $this->end_date->format('Y-m-d') : null,
            'phase' => $subscription?->phases?->phase,
            'status' => $this->status,
            'image' => $this->getEntityImage(),
            'role_id' => $roleId,
            'role_name' => $roleName,
            'is_agreement_signed' => $this->entity?->has_sign ?? false,
            'is_free_trail' => (bool) ($this->entity->is_free_trail ?? false),
            'free_trail_end_date' => ($this->entity->free_trail_end_date ?? null)?->toDateString(),
            'permissions' => $permissionNames,
        ];
    }
}
