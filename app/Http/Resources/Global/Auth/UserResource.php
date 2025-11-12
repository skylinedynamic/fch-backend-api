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
        // Derive current business id if the employee relation is loaded (no lazy loads)
        $businessId = $this->relationLoaded('employee') ? optional($this->employee)->business_id : null;
        if ($businessId === null && $this->relationLoaded('assignedBusinesses')) {
            $businessId = optional($this->assignedBusinesses->first())->id;
        }

        // Resolve role name from preloaded roles only (no lazy loads)
        $roleName = null;
        if ($this->relationLoaded('roles')) {
            $roleName = optional($this->roles->first())->name;
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
        if ($businessId !== null) {
            $allPerms = $allPerms->where('business_id', $businessId);
        }
        $permissionNames = $allPerms->pluck('name')
            ->unique()
            ->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'user_type' => $this->user_type,
            'status' => $this->status,
            'image' => $this->image,
            'role' => $roleName,
            'permissions' => $permissionNames,
            'business_id' => $businessId,
        ];
    }
}