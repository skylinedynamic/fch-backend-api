<?php

namespace App\Services\FCH\Common\ManageAgreement;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

/**
 * Keeps the images inside agreement HTML/JSON (logo, signature) on disk
 * instead of as base64 data URIs in the database.
 *
 * extract() writes each image once, named by the SHA-256 of its bytes, and
 * replaces its data URI with `agreement-image:{sha256}.{type}`; inline() turns
 * those references back into the very same data URIs. An image is only moved
 * when it can be put back byte for byte, so a signed agreement reads back
 * exactly as it was signed.
 */
class AgreementImageStore
{
    // data URI subtype => file extension; the extension is how inline() knows the subtype back.
    private const TYPES = [
        'png' => 'png',
        'jpeg' => 'jpeg',
        'jpg' => 'jpg',
        'gif' => 'gif',
        'webp' => 'webp',
        'svg+xml' => 'svg',
        'heic' => 'heic',
        'heif' => 'heif',
        'avif' => 'avif',
        'bmp' => 'bmp',
    ];

    private const REFERENCE_PREFIX = 'agreement-image:';

    /**
     * @param  bool  $dryRun  work out the references without writing any file
     */
    public function extract(?string $text, bool $dryRun = false): ?string
    {
        if ($text === null || !str_contains($text, 'data:image/')) {
            return $text;
        }

        return preg_replace_callback(
            '#data:image/([a-z0-9.+-]+);base64,([A-Za-z0-9+/]+={0,2})#',
            function (array $match) use ($dryRun): string {
                $extension = self::TYPES[$match[1]] ?? null;
                $bytes = base64_decode($match[2], true);

                if ($extension === null || $bytes === false || base64_encode($bytes) !== $match[2]) {
                    return $match[0];
                }

                $name = hash('sha256', $bytes) . '.' . $extension;
                $path = $this->path($name);

                if (!$dryRun && !$this->disk()->exists($path)) {
                    $this->disk()->put($path, $bytes);
                }

                return self::REFERENCE_PREFIX . $name;
            },
            $text
        );
    }

    public function inline(?string $text): ?string
    {
        if ($text === null || !str_contains($text, self::REFERENCE_PREFIX)) {
            return $text;
        }

        $subtypes = array_flip(self::TYPES);

        return preg_replace_callback(
            '#' . preg_quote(self::REFERENCE_PREFIX, '#') . '([a-f0-9]{64})\.(' . implode('|', array_map('preg_quote', self::TYPES)) . ')\b#',
            function (array $match) use ($subtypes): string {
                $bytes = $this->disk()->get($this->path("{$match[1]}.{$match[2]}"));

                if ($bytes === null) {
                    Log::warning('Agreement image missing from disk', ['image' => "{$match[1]}.{$match[2]}"]);
                    return $match[0];
                }

                return 'data:image/' . $subtypes[$match[2]] . ';base64,' . base64_encode($bytes);
            },
            $text
        );
    }

    /** The images' folder: private, on the disk config/files.php lists it under. */
    public const FOLDER = Folder::AGREEMENT_IMAGES;

    private function disk(): Filesystem
    {
        return Storage::disk(Disk::UPLOADS);
    }

    private function path(string $name): string
    {
        return self::FOLDER . '/' . $name;
    }
}
