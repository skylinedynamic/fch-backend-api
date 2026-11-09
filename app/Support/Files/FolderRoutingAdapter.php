<?php

namespace App\Support\Files;

use Closure;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * The storage behind the `uploads` disk (BUG-016). A path goes to the disk its
 * first folder is listed under in config/files.php private_folders, and to the
 * public disk otherwise. The disks are looked up on every call, so a test's
 * Storage::fake() of either one is respected.
 */
class FolderRoutingAdapter implements FilesystemAdapter
{
    /** @param Closure(): array<string, string> $privateFolders folder => disk */
    public function __construct(private string $publicDisk, private Closure $privateFolders)
    {
    }

    /** The disk name this path lives on. */
    public function diskFor(string $path): string
    {
        $folder = explode('/', trim(str_replace('\\', '/', $path), '/'), 2)[0];
        $folders = ($this->privateFolders)();

        return $folder !== '' && isset($folders[$folder]) ? $folders[$folder] : $this->publicDisk;
    }

    public function isPrivate(string $path): bool
    {
        return $this->diskFor($path) !== $this->publicDisk;
    }

    private function adapter(string $path): FilesystemAdapter
    {
        return Storage::disk($this->diskFor($path))->getAdapter();
    }

    public function fileExists(string $path): bool
    {
        return $this->adapter($path)->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->adapter($path)->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->adapter($path)->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->adapter($path)->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        return $this->adapter($path)->read($path);
    }

    public function readStream(string $path)
    {
        return $this->adapter($path)->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->adapter($path)->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->adapter($path)->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->adapter($path)->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter($path)->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->adapter($path)->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->adapter($path)->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->adapter($path)->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->adapter($path)->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        // The root holds folders of both disks.
        if (trim($path, '/') === '') {
            $disks = array_unique([$this->publicDisk, ...array_values(($this->privateFolders)())]);
            $private = array_keys(($this->privateFolders)());

            foreach ($disks as $disk) {
                foreach (Storage::disk($disk)->getAdapter()->listContents($path, $deep) as $item) {
                    $folder = explode('/', trim($item->path(), '/'), 2)[0];
                    $belongsHere = in_array($folder, $private, true)
                        ? $this->diskFor($folder) === $disk
                        : $disk === $this->publicDisk;
                    if ($belongsHere) {
                        yield $item;
                    }
                }
            }

            return;
        }

        yield from $this->adapter($path)->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if ($this->diskFor($source) === $this->diskFor($destination)) {
            $this->adapter($source)->move($source, $destination, $config);

            return;
        }

        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($this->diskFor($source) === $this->diskFor($destination)) {
            $this->adapter($source)->copy($source, $destination, $config);

            return;
        }

        $stream = $this->readStream($source);
        try {
            $this->writeStream($destination, $stream, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
