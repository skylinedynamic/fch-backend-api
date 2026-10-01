<?php

namespace App\Services\FCH\Common\Notification;

use App\Models\Common\NotificationType;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves a notification type key to its definition: the config catalogue
 * (config/notifications.php types) overlaid with the notification_types row
 * of the same key. Database rows are cached; NotificationType clears the
 * cache whenever a row is saved or deleted.
 */
class NotificationTypeRegistry
{
    private const CACHE_KEY = 'notification_types.overrides';

    public function find(string $key): ?NotificationTypeDefinition
    {
        // Keys contain dots, so index the array rather than using a dotted config() path.
        $definition = config('notifications.types', [])[$key] ?? null;
        $override = $this->overrides()[$key] ?? null;

        if (!is_array($definition) && !$override) {
            return null;
        }

        $merged = array_merge(
            is_array($definition) ? $definition : [],
            array_filter([
                'name' => $override['name'] ?? null,
                'category' => $override['category'] ?? null,
                'icon' => $override['icon'] ?? null,
                'channels' => $override['channels'] ?? null,
                'title' => $override['title_template'] ?? null,
                'body' => $override['body_template'] ?? null,
                'url' => $override['url_template'] ?? null,
                'is_active' => $override['is_active'] ?? null,
            ], fn ($value) => $value !== null)
        );

        return NotificationTypeDefinition::fromArray($key, $merged);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => NotificationType::query()
            ->get()
            ->keyBy('key')
            ->map(fn (NotificationType $type) => $type->only([
                'name', 'category', 'icon', 'channels', 'title_template', 'body_template', 'url_template', 'is_active',
            ]))
            ->all());
    }
}
