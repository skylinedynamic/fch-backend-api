<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerPaymentProviderListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Expects the partner's linkage to be eager-loaded and assigned to the
     * `linkage` attribute by the caller (see PartnerPaymentProviderController::index)
     * to avoid an N+1 query per provider.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $linkage = $this->linkage ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug ?? strtolower(str_replace([' ', '.'], '', $this->name)),
            'logo' => $this->logo,
            'status' => $linkage ? (bool) $linkage->status : false,
            'is_default' => $linkage ? (bool) $linkage->is_default : false,
            'is_configured' => $linkage ? (!empty($linkage->configuration)) : false,
        ];
    }
}
