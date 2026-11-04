<?php

namespace App\Support\Files;

use Illuminate\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * The name a file is kept under on disk: its readable name first, then a short
 * random suffix - "tmfs-articles-of-organization_3f9a2c1e.pdf" - so two files
 * with the same name never overwrite each other, and no path or odd character
 * from the upload reaches the disk. The original name belongs on the record.
 */
final class StoredFileName
{
    public static function make(string $originalName, ?string $extension = null): string
    {
        $extension = self::extension($originalName, $extension);
        $base = $extension !== '' && str_ends_with(strtolower($originalName), '.' . $extension)
            ? substr(basename(str_replace('\\', '/', $originalName)), 0, -(strlen($extension) + 1))
            : basename(str_replace('\\', '/', $originalName));

        $slug = Str::limit(Str::slug(Str::ascii($base)), config('files.name.max_slug_length'), '');
        $slug = trim($slug, '-') ?: config('files.name.fallback');
        $length = config('files.name.suffix_length');
        $suffix = substr(bin2hex(random_bytes(intdiv($length + 1, 2))), 0, $length);

        return $slug . '_' . $suffix . ($extension !== '' ? ".{$extension}" : '');
    }

    /**
     * The name an upload people see in the file manager or an activity is kept
     * under: its own name first, then the time - "Bank_Statement_1793773507.pdf".
     * Letters, digits, "-" and "_" are kept as typed; anything else becomes
     * "_". Given the disk and folder, a name already taken gets _2, _3, ... so
     * two uploads in the same second never overwrite each other.
     */
    public static function timed(string $originalName, ?Filesystem $disk = null, string $directory = ''): string
    {
        $original = basename(str_replace('\\', '/', $originalName));
        $extension = self::extension($original, null);
        $base = $extension !== '' ? pathinfo($original, PATHINFO_FILENAME) : $original;
        $safe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $base);
        $safe = trim((string) $safe, '_') === '' ? config('files.name.fallback') : $safe;

        $stem = $safe . '_' . now()->timestamp;
        $dot = $extension !== '' ? ".{$extension}" : '';
        $name = $stem . $dot;

        $directory = trim($directory, '/');
        for ($n = 1; $disk && $disk->exists(ltrim("{$directory}/{$name}", '/')); $n++) {
            $name = "{$stem}_{$n}{$dot}";
        }

        return $name;
    }

    /** The lock one upload name in one folder is picked and written under. */
    public static function lockKey(string $directory, string $originalName): string
    {
        return 'uploads:name:' . sha1(trim($directory, '/') . '|' . basename(str_replace('\\', '/', $originalName)));
    }

    /**
     * Keep an upload in the folder under its timed name and return its path.
     * Picking the name and writing the file happen under a lock for that
     * folder and name: two uploads of one name at the same moment never take
     * the same name - the second waits (up to $waitSeconds), then gets _1.
     *
     * @throws LockTimeoutException when the lock is not had in time
     */
    public static function store(UploadedFile $file, Filesystem $disk, string $directory, int $waitSeconds = 10): string|false
    {
        $directory = trim($directory, '/');
        $original = $file->getClientOriginalName();

        return Cache::lock(self::lockKey($directory, $original), 30)
            ->block($waitSeconds, function () use ($file, $disk, $directory, $original) {
                return $disk->putFileAs($directory, $file, self::timed($original, $disk, $directory));
            });
    }

    /** A name that carries nothing of the original, for a file whose name says too much (a credit report). */
    public static function anonymous(string $extension): string
    {
        $extension = self::extension('', $extension);

        return (string) Str::uuid() . ($extension !== '' ? ".{$extension}" : '');
    }

    private static function extension(string $originalName, ?string $extension): string
    {
        $extension = $extension ?? pathinfo($originalName, PATHINFO_EXTENSION);

        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $extension));
    }
}
