<?php

namespace App\Http\Resources\FCH\Role;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        $permissionNames = [];
        if ($this->relationLoaded('permissions')) {
            $permissionNames = $this->permissions->pluck('name')->unique()->values();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_static' => $this->is_static,
            'permissions' => $permissionNames,
            'users_count' => (int) ($this->users_count ?? ($this->relationLoaded('users') ? $this->users->count() : 0)),
        ];
    }
}