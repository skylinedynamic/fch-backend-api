<?php

namespace App\Http\Resources\FCH\Common\Application;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DownloadHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');
        
        return [
            'id' => $this->id,
            'user_name' => $this->user_name,
            'user_type' => $this->user_type,
            'application_number' => $this->application_number,
            'certificate_id' => $this->certificate_id,
            'application_version' => $this->application_version,
            'sha256_hash' => $this->sha256_hash,
            'ip_address' => $this->ip_address,
            'action' => $this->action,
            'file_type' => $this->file_type,
            'downloaded_at' => $this->downloaded_at?->timezone($timezone)->format('Y-m-d h:i A'),
            'details' => is_string($this->details) ? json_decode($this->details, true) : $this->details,
        ];
    }
}
