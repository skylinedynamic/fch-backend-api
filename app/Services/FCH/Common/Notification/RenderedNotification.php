<?php

namespace App\Services\FCH\Common\Notification;

/**
 * The text a user actually sees, rendered once at send time and stored as a
 * snapshot, so later template edits never rewrite past notifications.
 */
final class RenderedNotification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly ?string $icon,
        public readonly string $category,
    ) {
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
            'category' => $this->category,
        ];
    }
}
