<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User\User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->full_name,
            'email' => $this->email,
            'user_type' => $this->user_type,
            'image' => $this->image,
            'phone_number' => $this->phone_number,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_method' => $this->getTwoFactorMethod(),
        ];
    }
}