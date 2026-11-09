<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;

/**
 * A profile picture chosen from the file manager, sent as a path inside the
 * user's own folder (User::profilePictureFolder()).
 *
 * BUG-014 (QA round 2): the path was any string, so a 12 MB file that only
 * started like a PNG - or a PDF, or a ZIP - could be set as the picture. It now
 * has to be a JPG/JPEG/PNG that exists in that folder, is within the picture
 * size limit and opens as an image. Pair it with NoPathTraversal, so a path
 * cannot climb out of the folder.
 */
class StoredProfilePicture implements ValidationRule
{
    private const DISK = Disk::UPLOADS;

    public function __construct(private readonly ?string $folder) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));

        if (!in_array($extension, config('uploads.profile_picture_extensions'), true)) {
            $fail(__('uploads.picture_type'));

            return;
        }

        $disk = Storage::disk(self::DISK);
        $path = ltrim(($this->folder !== null ? $this->folder . '/' : '') . ltrim($value, '/'), '/');

        if (!$disk->exists($path)) {
            $fail(__('uploads.picture_missing'));

            return;
        }

        $maxKilobytes = SafeUpload::maxKilobytesFor($extension);

        if ($disk->size($path) > $maxKilobytes * 1024) {
            $fail(__('uploads.too_large', ['size' => intdiv($maxKilobytes, 1024)]));

            return;
        }

        if (!SafeUpload::opensAsPicture($extension, (string) $disk->get($path))) {
            $fail(__('uploads.invalid_image'));
        }
    }
}
