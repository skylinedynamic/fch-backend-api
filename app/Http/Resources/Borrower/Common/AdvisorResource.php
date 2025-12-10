<?php

namespace App\Http\Resources\Borrower\Common;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvisorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone_number,
            'avatar' => $this->avatar,
            'created_at' => $this->created_at,
        ];
    }
}
