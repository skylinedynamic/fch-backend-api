<?php

namespace App\Http\Resources\FCH\Notification;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox entry. Same fields as the real-time broadcast payload, so the
 * portals can drop a pushed notification straight into the cached list.
 */
class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'url' => $data['url'] ?? null,
            'icon' => $data['icon'] ?? null,
            'category' => $data['category'] ?? null,
            'is_read' => $this->read_at !== null,
            'read_at' => toTimezone($this->read_at, null, 'c'),
            'created_at' => toTimezone($this->created_at, null, 'c'),
        ];
    }
}
