<?php

namespace App\Http\Resources\TwoFaAuth;

use Illuminate\Http\Resources\Json\JsonResource;

class TwoFaSetupResource extends JsonResource
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
            'message' => $this['message'] ?? null,
            'method' => $this['method'] ?? null,
            'secret' => $this['secret'] ?? null,
            'qr_svg' => $this['qr_svg'] ?? null,
            'expires_in_minutes' => $this['expires_in_minutes'] ?? null,
        ];
    }
}