<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Storage;

/**
 * Hands out a private file (BUG-016). The caller checks access first - the
 * owning record's visibleTo()/reviewableBy() for the signed-in user - and then
 * asks for a URL here: signed for that one path, working for
 * files.signed_url_minutes, served at /private-files (never /storage).
 */
class ProtectedFiles
{
    public function temporaryUrl(string $disk, string $path): string
    {
        return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(config('files.signed_url_minutes')));
    }
}
