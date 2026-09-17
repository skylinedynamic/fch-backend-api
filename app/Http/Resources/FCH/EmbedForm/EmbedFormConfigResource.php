<?php

namespace App\Http\Resources\FCH\EmbedForm;

use App\Support\EmbedForm\EmbedFormLocation;
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
        // Resolved from the config's OWN partner, not the authenticated user's.
        // EmbedFormController::getConfig() lets a host admin fetch another
        // partner's config with ?partner_id=, and the previous helper read
        // auth()->user()->partner - so an admin inspecting partner X's form was
        // shown a link built on the host's domain rather than X's.
        $location = EmbedFormLocation::forPartner($this->partner);

        return [
            'id' => $this->id,
            'partner_id' => $this->partner_id,
            'public_key' => $this->public_key,
            'form_title' => $this->form_title,
            'allowed_domains' => $this->allowed_domains ?? [],
            'notification_email' => $this->notification_email,
            'is_active' => (bool) $this->is_active,

            // Null rather than a guess when the partner has nowhere to publish.
            // A link built on a fallback domain is worse than no link: it looks
            // valid, gets pasted into a website, and 404s for every applicant.
            'embed_snippet' => $location->embedSnippet($this->public_key),
            'direct_url' => $location->formUrl($this->public_key),

            // What the settings screen shows in place of the link, and why.
            'form_url_available' => $location->isAvailable(),
            'form_url_warning' => $location->warning(),

            'submissions_count' => $this->submissions()->count(),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }
}
