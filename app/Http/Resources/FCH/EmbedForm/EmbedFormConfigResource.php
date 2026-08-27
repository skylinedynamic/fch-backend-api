<?php

namespace App\Http\Resources\FCH\EmbedForm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmbedForm\EmbedFormConfig */
class EmbedFormConfigResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $baseUrl = config('app.url');

        return [
            'id' => $this->id,
            'partner_id' => $this->partner_id,
            'public_key' => $this->public_key,
            'form_title' => $this->form_title,
            'allowed_domains' => $this->allowed_domains ?? [],
            'notification_email' => $this->notification_email,
            'is_active' => (bool) $this->is_active,
            'embed_snippet' => "<iframe src=\"{$baseUrl}/form/{$this->public_key}\" width=\"100%\" height=\"800\" frameborder=\"0\" style=\"border:none;border-radius:8px;\"></iframe>",
            'direct_url' => "{$baseUrl}/form/{$this->public_key}",
            'submissions_count' => $this->submissions()->count(),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }
}
