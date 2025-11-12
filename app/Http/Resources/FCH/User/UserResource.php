<?php

namespace App\Http\Resources\FCH\User;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone_number' => $this->phone_number,
            'role'         => $this->getRoleNames()->first(),
            'status'        => $this->status
        ];
    }
}
