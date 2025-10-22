<?php

namespace App\Http\Resources\TwoFaAuth;

use Illuminate\Http\Resources\Json\JsonResource;

class TwoFaStatusResource extends JsonResource
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
            'enabled' => (bool) ($this['enabled'] ?? false),
            'method' => $this['method'] ?? null,
            'confirmed_at' => $this['confirmed_at'] ?? null,
            'has_recovery_codes' => (bool) ($this['has_recovery_codes'] ?? false),
            'locked_until' => $this['locked_until'] ?? null,
        ];
    }
}