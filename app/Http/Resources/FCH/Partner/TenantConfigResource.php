<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantConfigResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'active_custom_url' => (bool) $this->active_custom_url,
            'partner_domain' => $this->partner_domain,
            'borrower_domain' => $this->borrower_domain,
            'partner_subdomain' => $this->partner_subdomain,
        ];
    }
}
