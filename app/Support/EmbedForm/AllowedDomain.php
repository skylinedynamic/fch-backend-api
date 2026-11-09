<?php

namespace App\Support\EmbedForm;

/**
 * One entry of an embedded form's Allowed Domains (R2 BUG-035).
 *
 * An entry is stored as its bare host ("x.com"): no scheme, path, port or
 * "www.", lowercase. One typed with http:// is stored as "http://x.com", which
 * allows the site over http as well as https; any other entry is https only.
 */
final class AllowedDomain
{
    public const LOCALHOST = 'localhost';

    private const HTTP = 'http://';

    /**
     * A host name: dot-separated labels of letters, digits and inner hyphens,
     * ending in a letter TLD (or an internationalised "xn--" one). Rules out
     * single names ("intranet") and IP addresses.
     */
    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * The host an entry names - lowercase, without "www." - or null when it
     * names none (another scheme, or nothing left once the rest is removed).
     */
    public static function host(string $entry): ?string
    {
        $value = strtolower(trim($entry));

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#', $value, $scheme)) {
            if (!in_array($scheme[1], ['http', 'https'], true)) {
                return null;
            }
            $value = substr($value, strlen($scheme[0]));
        }

        // The authority ends at the first path, query or fragment character.
        $value = preg_split('#[/?\#]#', $value, 2)[0];
        // user:pass@ and :port are not part of the host.
        $value = substr($value, (int) strrpos('@' . $value, '@'));
        $value = preg_replace('/:\d*$/', '', $value);
        $value = preg_replace('/^www\./', '', rtrim($value, '.'));

        return $value === '' ? null : $value;
    }

    /** True when the entry was typed (or stored) with http://. */
    public static function allowsHttp(string $entry): bool
    {
        return str_starts_with(strtolower(trim($entry)), self::HTTP);
    }

    /** The entry as stored, or null when it names no host. */
    public static function normalize(string $entry): ?string
    {
        $host = self::host($entry);

        if ($host === null) {
            return null;
        }

        return self::allowsHttp($entry) ? self::HTTP . $host : $host;
    }

    public static function isWildcard(string $host): bool
    {
        return str_contains($host, '*');
    }

    public static function isLocalhost(string $host): bool
    {
        return $host === self::LOCALHOST || str_ends_with($host, '.' . self::LOCALHOST);
    }

    public static function isValidHost(string $host): bool
    {
        return (bool) preg_match(self::HOST_PATTERN, $host);
    }
}
