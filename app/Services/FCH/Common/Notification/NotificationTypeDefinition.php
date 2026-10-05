<?php

namespace App\Services\FCH\Common\Notification;

/**
 * One notification type after the config catalogue and its database
 * override (if any) have been merged.
 */
final class NotificationTypeDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $icon,
        public readonly array $channels,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly bool $isActive,
        public readonly ?string $sms = null,
    ) {
    }

    /**
     * @param array{name?: string, category?: string, icon?: ?string, channels?: array, title?: string, body?: string, url?: ?string, is_active?: bool, sms?: ?string} $definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            name: $definition['name'] ?? $key,
            category: $definition['category'] ?? 'general',
            icon: $definition['icon'] ?? null,
            channels: array_values($definition['channels'] ?? ['database', 'broadcast']),
            title: (string) ($definition['title'] ?? ''),
            body: (string) ($definition['body'] ?? ''),
            url: $definition['url'] ?? null,
            isActive: (bool) ($definition['is_active'] ?? true),
            sms: isset($definition['sms']) ? (string) $definition['sms'] : null,
        );
    }
}
