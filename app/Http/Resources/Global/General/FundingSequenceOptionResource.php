<?php

namespace App\Http\Resources\Global\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One option of the Funding Sequence dropdown. Only the id and name: the
 * model's appended engine status runs several queries per sequence.
 */
class FundingSequenceOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
