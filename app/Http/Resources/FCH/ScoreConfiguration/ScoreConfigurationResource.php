<?php

namespace App\Http\Resources\FCH\ScoreConfiguration;

use Illuminate\Http\Resources\Json\JsonResource;

class ScoreConfigurationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'models' => $this->resource['models'] ?? [],
            'factors' => $this->resource['factors'] ?? [],
            'band_mappings' => $this->resource['band_mappings'] ?? [],
            'readiness_bands' => $this->resource['readiness_bands'] ?? [],
            'play_eligibility' => $this->resource['play_eligibility'] ?? [],
        ];
    }
}
