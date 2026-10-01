<?php

namespace App\Http\Resources\FCH\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            // A new sign-in email awaiting confirmation from that address.
            'pending_email' => $this->pending_email,
            'phone_number' => $this->phone_number,
            'role'         => $this->getRoleNames()->first(),
            // Resolved from preloaded roles only, so this never triggers a lazy
            // load. The details screen needs the id to preselect the dropdown —
            // the role name alone can't identify it.
            'role_id'      => $this->relationLoaded('roles') ? optional($this->roles->first())->id : null,
            'status'        => $this->status,
            'image'         => (function ($user) {
                if (!$user || !$user->entity) return null;
                $entity = $user->entity;
                if ($entity instanceof Partner) {
                    return $entity->getRawOriginal('partner_image');
                } elseif ($entity instanceof Employee || $entity instanceof Borrower) {
                    return $entity->getRawOriginal('image');
                }
                return null;
            })($this->resource),
            'invoice_id'    => $this->invoice_id,
        ];
    }
}
