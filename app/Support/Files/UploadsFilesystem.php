<?php

namespace App\Support\Files;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;

/**
 * The `uploads` disk (BUG-016): FolderRoutingAdapter underneath, so a file in a
 * private folder is kept on the private disk. Its url() is what a client may
 * open: a short-lived signed URL (ProtectedFiles) for a private file, the
 * public /storage URL for any other. Hand a private file's URL only to someone
 * who has passed the owning record's access check.
 */
class UploadsFilesystem extends FilesystemAdapter
{
    public function __construct(private FolderRoutingAdapter $router, array $config = [])
    {
        parent::__construct(new Filesystem($router, $config), $router, $config);
    }

    public static function make(array $config): self
    {
        $public = $config['public_disk'] ?? Disk::PUBLIC;

        return new self(new FolderRoutingAdapter($public, fn () => config('files.private_folders', [])), $config);
    }

    public function diskFor(string $path): string
    {
        return $this->router->diskFor($path);
    }

    public function isPrivate(string $path): bool
    {
        return $this->router->isPrivate($path);
    }

    public function url($path)
    {
        return $this->isPrivate($path)
            ? Storage::disk($this->diskFor($path))->temporaryUrl($path, now()->addMinutes(config('files.signed_url_minutes')))
            : Storage::disk($this->diskFor($path))->url($path);
    }

    public function path($path)
    {
        return Storage::disk($this->diskFor($path))->path($path);
    }

    public function providesTemporaryUrls()
    {
        return true;
    }

    public function temporaryUrl($path, $expiration, array $options = [])
    {
        return Storage::disk($this->diskFor($path))->temporaryUrl($path, $expiration, $options);
    }
}
