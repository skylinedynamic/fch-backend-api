<?php

namespace App\Services\FCH\Admin\Configuration;

use App\Models\Common\AppConfiguration;

class AppConfigurationService
{
    /**
     * Get all app configurations for an entity.
     */
    public function getAllConfigurations(string $entityType, int $entityId): array
    {
        return AppConfiguration::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get specific app configurations for an entity.
     */
    public function getConfigurations(array $keys, string $entityType, int $entityId): array
    {
        return AppConfiguration::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('key', $keys)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Save multiple app configurations for an entity.
     */
    public function saveConfigurations(array $settings, string $entityType, int $entityId): void
    {
        foreach ($settings as $key => $value) {
            AppConfiguration::set($key, $value, $entityType, $entityId);
        }
    }
}
