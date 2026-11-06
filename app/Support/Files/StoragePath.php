<?php

namespace App\Support\Files;

/**
 * A file's path on the uploads disk, from whatever was saved or handed out for
 * it (BUG-016): a public /storage URL, a signed /private-files URL (its query
 * dropped), or the path itself. A URL pointing anywhere else, or a path that
 * climbs out with "..", gives null - never a path to read.
 */
final class StoragePath
{
    private const URL_PREFIXES = ['/private-files/', '/storage/'];

    public static function fromUrl(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $path = null;

        foreach (self::URL_PREFIXES as $prefix) {
            if (str_contains($value, $prefix)) {
                $path = substr($value, strpos($value, $prefix) + strlen($prefix));
                break;
            }
        }

        if ($path === null) {
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
                return null;
            }
            $path = $value;
        }

        $path = rawurldecode(strtok($path, '?#') ?: '');
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }
}
