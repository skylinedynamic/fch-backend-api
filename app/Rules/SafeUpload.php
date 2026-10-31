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
 * A value that is not an upload (a stored path, null) is left to the other
 * rules on the field.
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
        }
    }
}
