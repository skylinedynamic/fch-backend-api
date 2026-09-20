<?php

namespace App\Http\Resources\Global\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One country, with its states nested.
 *
 * The canonical response shape for GET /common/locations, and the contract both
 * portals read. Defined here rather than by handing config straight to the
 * client, so a key added to config/locations.php does not silently become part
 * of the public API.
 */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'] ?? null,
            'code' => $this->resource['code'] ?? null,
            'currency' => $this->resource['currency'] ?? null,
            'phone_code' => $this->resource['phone_code'] ?? null,
            'flag' => $this->resource['flag'] ?? null,
            'states' => LocationStateResource::collection(
                $this->resource['states'] ?? []
            )->resolve($request),
        ];
    }
}
