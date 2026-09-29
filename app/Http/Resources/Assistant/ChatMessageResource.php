<?php

namespace App\Http\Resources\Assistant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'message_id' => $this->uuid,
            'role' => $this->role,
            'content' => $this->content,
            'actions' => $this->actions ?? [],
            'sources' => $this->sources ?? [],
            // toTimezone() (app/Helpers/helpers.php) reads the caller's own
            // X-Timezone header — the same header apiClient already attaches
            // on every request in both frontends — so this is already
            // correct for the viewer without any client-side date math.
            'created_at' => toTimezone($this->created_at, null, 'M j, Y g:i A'),
        ];
    }
}
