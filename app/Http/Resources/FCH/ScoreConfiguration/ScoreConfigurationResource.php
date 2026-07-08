<?php

namespace App\Http\Resources\FCH\ScoreConfiguration;

use Illuminate\Http\Resources\Json\JsonResource;

class ScoreConfigurationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'funding_readiness' => $this->resource['funding_readiness'] ?? [],
            'approval_probability' => $this->resource['approval_probability'] ?? [],
            'approval_odds' => $this->resource['approval_odds'] ?? [],
            'general_setting' => $this->resource['general_setting'] ?? [],
            'models' => $this->resource['models'] ?? [],
        ];
    }
}
