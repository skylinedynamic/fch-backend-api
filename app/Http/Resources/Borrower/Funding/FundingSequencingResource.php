<?php

namespace App\Http\Resources\Borrower\Funding;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Funding\FundingSequencing;

class FundingSequencingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'description' => $this->description,
            'amount_range' => $this->amount_range,
            'terms' => $this->terms,
            'action_url' => $this->action_url,
            'funding_type_id' => $this->funding_type_id,
            'funding_group_id' => $this->funding_group_id,
            'speed_to_funds' => $this->speed_to_funds,
            'uses' => $this->uses,
            'qualify_reasons' => $this->qualify_reasons ?? [],
            'missing_documents' => $this->missing_documents ?? [],
            'readiness_factors' => $this->readiness_factors ?? [],
            'eligibility_status' => $this->eligibility_status,
            'eligibility_label' => $this->eligibility_label,
        ];
    }
}
