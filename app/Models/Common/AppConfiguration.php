<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type', 
        'entity_id', 
        'key', 
        'value'
    ];

    /**
     * Set a configuration value
     */
    public static function set(string $key, $value, ?string $entityType = null, ?int $entityId = null)
    {
        return self::updateOrCreate(
            [
                'key' => $key,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ],
            [
                'value' => (string) $value
            ]
        );
    }

    /**
     * Get a configuration value
     */
    public static function get(string $key, $default = null, ?string $entityType = null, ?int $entityId = null)
    {
        $config = self::where('key', $key)
            ->when($entityType, fn($q) => $q->where('entity_type', $entityType))
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->first();

        return $config ? $config->value : $default;
    }
}
