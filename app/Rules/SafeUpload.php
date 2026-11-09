<?php

namespace App\Rules;

use App\Support\Uploads\SvgSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An upload of a type the server accepts, judged by its name and by its
 * content (BUG-015). Lists live in config/uploads.php.
 *
 * - SafeUpload::document(): documents, file manager uploads, attachments.
 *   Never SVG, HTML or script.
 * - SafeUpload::image(): logos and pictures. SVG is accepted and stripped of
 *   its scripts in place; anything else that a browser would run is refused.
 *
 * Either way (BUG-014) a picture may be up to 5 MB and anything else up to
 * 20 MB, and a JPG/PNG/GIF/WebP/BMP has to open as one - a PNG signature
 * followed by zeros is refused.
 *
 * A value that is not an upload (a stored path, null) is left to the other
 * rules on the field; see StoredProfilePicture for a picture chosen by path.
 */
class SafeUpload implements ValidationRule
{
    private const SVG_EXTENSION = 'svg';

    private function __construct(
        private readonly array $extensions,
        private readonly bool $sanitizesSvg,
    ) {}

    public static function document(): self
    {
        return new self(config('uploads.document_extensions'), false);
    }

    public static function image(): self
    {
        return new self(config('uploads.image_extensions'), true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (!in_array($extension, $this->extensions, true)) {
            $fail(__('uploads.type_not_allowed', ['extension' => $extension !== '' ? ".{$extension}" : '-']));

            return;
        }

        $maxKilobytes = self::maxKilobytesFor($extension);

        if ($value->getSize() > $maxKilobytes * 1024) {
            $fail(__('uploads.too_large', ['size' => intdiv($maxKilobytes, 1024)]));

            return;
        }

        if ($this->sanitizesSvg && $extension === self::SVG_EXTENSION) {
            if (!SvgSanitizer::sanitizeInPlace($value)) {
                $fail(__('uploads.invalid_svg'));
            }

            return;
        }

        // The content, not the name: HTML saved as .jpg is still HTML. Read
        // from the bytes on disk rather than anything the upload reports.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath());

        if (in_array($detected, config('uploads.script_mime_types'), true)) {
            $fail(__('uploads.unsafe_content'));

            return;
        }

        if (!self::opensAsPicture($extension, (string) file_get_contents($value->getRealPath()))) {
            $fail(__('uploads.invalid_image'));
        }
    }

    /** The size limit for a file of this extension: a picture's or a document's. */
    public static function maxKilobytesFor(string $extension): int
    {
        return in_array(strtolower($extension), config('uploads.picture_extensions'), true)
            ? (int) config('uploads.max_kilobytes.picture')
            : (int) config('uploads.max_kilobytes.document');
    }

    /**
     * False for a JPG/PNG/GIF/WebP/BMP that PHP cannot read as a picture. Other
     * types are not checked here and pass.
     */
    public static function opensAsPicture(string $extension, string $contents): bool
    {
        if (!in_array(strtolower($extension), config('uploads.decodable_picture_extensions'), true)) {
            return true;
        }

        $info = @getimagesizefromstring($contents);

        return $info !== false && $info[0] > 0 && $info[1] > 0;
    }
}
