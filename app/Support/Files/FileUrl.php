<?php

namespace App\Support\Files;

use Illuminate\Support\Facades\Storage;

/**
 * The URL a client may open for a path on the uploads disk (BUG-016): a
 * short-lived signed URL when the path is in a private folder, the public
 * /storage URL - exactly as before - otherwise. Only hand a private file's URL
 * to someone who has passed its owner's access check.
 */
final class FileUrl
{
    public static function for(?string $path): ?string
    {
        $path = StoragePath::fromUrl($path);
        if ($path === null) {
            return null;
        }

        /** @var UploadsFilesystem $uploads */
        $uploads = Storage::disk(Disk::UPLOADS);

        return $uploads->isPrivate($path) ? $uploads->url($path) : encodeUrl(asset('storage/' . $path));
    }
}
