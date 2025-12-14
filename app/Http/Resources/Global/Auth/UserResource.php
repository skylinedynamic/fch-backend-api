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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'user_type' => $this->user_type,
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
            'is_host' => $this->partner?->is_host,
            'status' => $this->status,
            'image' => $this->image,
            'role_id' => $roleId,
            'role_name' => $roleName,
            'permissions' => $permissionNames,
        ];
    }
}