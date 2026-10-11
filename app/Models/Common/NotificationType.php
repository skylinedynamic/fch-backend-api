<?php

namespace App\Models\Common;

use App\Services\FCH\Common\Notification\NotificationTypeRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * A runtime override of a notification type from config/notifications.php
 * (or a type that only exists here). Null columns fall back to the config.
 */
class NotificationType extends Model
{
    protected $fillable = [
        'key',
        'name',
        'category',
        'icon',
        'channels',
        'title_template',
        'body_template',
        'url_template',
        'is_active',
    ];

    protected $casts = [
        'channels' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $forget = fn () => app(NotificationTypeRegistry::class)->forget();

        static::saved($forget);
        static::deleted($forget);
    }
}
