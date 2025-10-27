<?php

namespace App\Http\Resources\TwoFaAuth;

use Illuminate\Http\Resources\Json\JsonResource;

class TwoFaVerificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'verified' => (bool) ($this['verified'] ?? false),
            'message' => $this['message'] ?? null,
            'remaining_attempts' => $this['remaining_attempts'] ?? null,
            'locked_until' => $this['locked_until'] ?? null,
        ];
    }
}