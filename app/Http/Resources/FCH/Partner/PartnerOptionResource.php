<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A partner as a filter dropdown option: its id, name and company. */
class PartnerOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
        ];
    }
}
