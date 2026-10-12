<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The daily log, filed under a folder named for the current month.
 *
 * Why this exists rather than `date('F')` in config/logging.php: that expression
 * is evaluated when the config file is READ, and `php artisan config:cache`
 * reads it once and writes the result into bootstrap/cache/config.php as a
 * literal string. On a cached deployment the month is therefore frozen at
 * whatever it was when the deploy ran - October's logs land in `September/`, and
 * keep doing so until someone happens to rebuild the cache.
 *
 * Nothing announces that. The logs are still written, just to last month's
 * folder, so it is only noticed when someone goes looking for a recent entry
 * and the newest file is weeks old.
 *
 * Resolving the path here instead means it is computed when the channel is
 * first used, which is per process and after the config cache has been loaded -
 * correct whether or not the config is cached.
 *
 * The handler itself is still Laravel's own daily driver: Log::build() goes back
 * through LogManager::createDailyDriver(), so rotation, retention, level,
 * permissions and the line format stay identical to the built-in channel. Only
 * the path is ours.
 */
class MonthlyDailyLogger
{
    /**
     * @param  array<string, mixed>  $config  The channel's config from config/logging.php.
     */
    public function __invoke(array $config): LoggerInterface
    {
        return Log::build(array_merge($config, [
            // Delegate to the real daily driver; 'custom' here would recurse.
            'driver' => 'daily',
            // Year and month: month alone put June 2025 and June 2026 in one
            // folder, which the six-month pruning could then never remove.
            'path' => storage_path('logs/' . now()->format('Y/F') . '/laravel.log'),
        ]));
    }
}
