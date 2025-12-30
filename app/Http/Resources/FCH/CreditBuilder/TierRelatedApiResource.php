<?php

namespace App\Http\Resources\FCH\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierRelatedApiResource extends JsonResource
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
            'provider' => $this->provider,
            'base_url' => $this->base_url,
            'client_id' => $this->client_id,
            'additional_info' => $this->additional_info,
            'tier_id' => $this->tier_id,
        ];
    }
}
