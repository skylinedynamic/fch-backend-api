<?php

namespace App\Http\Resources\FCH\Common\Application;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
