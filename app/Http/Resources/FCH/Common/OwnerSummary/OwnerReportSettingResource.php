<?php

namespace App\Http\Resources\FCH\Common\OwnerSummary;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnerReportSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enabled' => (bool) $this->is_enabled,
            'recipients' => $this->recipients,
        ];
    }
}
