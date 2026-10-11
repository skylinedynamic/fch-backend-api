<?php

namespace App\Support\Branding;

use Illuminate\Support\Facades\Storage;

/**
 * A stored logo, as something Dompdf can actually draw.
 *
 * Dompdf will not fetch a remote URL unless remote access is enabled, and
 * turning that on for a PDF built from tenant-supplied data is a
 * request-forgery hole we have no need of: the file is on our own disk, so it
 * goes in as a data URI instead.
 *
 * The invoice template does this inline, in the middle of the view. This is the
 * same logic named once, so the next PDF that wants a logo does not become a
 * third copy of it.
 *
 * Returns null rather than a placeholder when there is nothing to draw: a PDF
 * with no logo is fine, and a broken-image box in an audit document is not.
 */
final class PdfLogo
{
    /**
     * Dompdf trusts the type a data URI declares rather than sniffing the
     * bytes, so a JPEG announced as a PNG simply does not render.
     */
    private const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /**
     * @param  string|null  $path  A branding path (`branding/logo.png`), or the
     *                             full storage URL callers reach for by habit.
     */
    public static function dataUri(?string $path): ?string
    {
        $relative = self::relativePath($path);

        if ($relative === null) {
            return null;
        }

        $disk = Storage::disk('public');

        // is_file, not exists: a directory passes exists() and then reading it
        // fails. Storage::fileExists() has the same distinction under the hood,
        // and works with the faked disk the tests use.
        if (!$disk->fileExists($relative)) {
            return null;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$extension] ?? 'image/png';

        $contents = $disk->get($relative);

        return $contents === null
            ? null
            : 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    /**
     * The first of these surfaces that has a file behind it.
     *
     * Branding already chains the logo keys, but only by VALUE: a key naming a
     * file that is no longer on disk still counts as set, so resolution stops
     * there and the document comes out with no mark at all. This chain is over
     * what can actually be drawn, so a stale path falls through to the next
     * surface.
     *
     * @param  array<int, string|null>  $paths  In the order they should be tried.
     */
    public static function firstAvailable(array $paths): ?string
    {
        foreach ($paths as $path) {
            $src = self::dataUri($path);

            if ($src !== null) {
                return $src;
            }
        }

        return null;
    }

    /**
     * Reduce whatever the caller had to a path on the public disk.
     */
    private static function relativePath(?string $path): ?string
    {
        $path = trim((string) $path);

        // 'null' is what an unset branding value serialises to in places that
        // wrote it as a string; it names no file.
        if ($path === '' || $path === 'null') {
            return null;
        }

        // A full URL names the same file - take everything after /storage/.
        if (preg_match('#^https?://#i', $path)) {
            $urlPath = (string) parse_url($path, PHP_URL_PATH);
            $path = preg_replace('#^/?storage/#', '', ltrim($urlPath, '/'));
        }

        $path = ltrim((string) $path, '/');

        return $path === '' ? null : $path;
    }
}
