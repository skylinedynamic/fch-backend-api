<?php

namespace App\Http\Resources\Global\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One state or province, with the cities configured for it.
 *
 * "Unknown" leads every city list by design - see config/locations.php - so it
 * is passed through untouched rather than filtered here.
 */
class LocationStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'] ?? null,
            'code' => $this->resource['code'] ?? null,
            'cities' => array_values($this->resource['cities'] ?? []),
        ];
    }
}
