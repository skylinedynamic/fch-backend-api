<?php

namespace App\Http\Resources\FCH\Funding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingSequencingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'amount_range' => $this->amount_range,
            'terms' => $this->terms,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'action_url' => $this->action_url,
            'funding_group_id' => $this->funding_group_id,
            'funding_type_id' => $this->funding_type_id,
            'speed_to_funds' => $this->speed_to_funds,
            'uses' => $this->uses,
            'qualify_reasons' => $this->qualify_reasons ?? [],
            'missing_documents' => $this->missing_documents ?? [],
            'readiness_factors' => $this->readiness_factors ?? [],
            'eligibility_status' => $this->eligibility_status,
            'eligibility_label' => $this->eligibility_label,
            'group' => $this->whenLoaded('group', function () {
                return [
                    'id' => $this->group->id,
                    'name' => $this->group->name,
                ];
            }),
            'type' => $this->whenLoaded('type', function () {
                return [
                    'id' => $this->type->id,
                    'name' => $this->type->name,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
