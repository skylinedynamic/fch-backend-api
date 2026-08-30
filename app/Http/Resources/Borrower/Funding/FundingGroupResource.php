<?php

namespace App\Http\Resources\Borrower\Funding;

use Illuminate\Http\Resources\Json\JsonResource;

class FundingGroupResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sequencings' => FundingSequencingResource::collection($this->whenLoaded('sequencings')),
        ];
    }
}
