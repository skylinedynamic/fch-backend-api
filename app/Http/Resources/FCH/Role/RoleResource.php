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
            'description' => $this->description,
            'code' => $this->code,
            'is_static' => $this->is_static,
            // For the signed-in viewer: may they change this role (Role::isEditableBy)?
            'is_editable' => $request->user() ? $this->resource->isEditableBy($request->user()) : false,
            'permissions' => $permissionNames,
            'users_count' => (int) ($this->users_count ?? ($this->relationLoaded('users') ? $this->users->count() : 0)),
        ];
    }
}