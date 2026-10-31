<?php

namespace App\Support\Uploads;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;

/**
 * Strips an uploaded SVG of everything that can run or reach out - <script>,
 * on* handlers, javascript: links, remote references - and writes the clean
 * markup back over the upload, so whatever stores it next stores the clean
 * file (BUG-015).
 */
class SvgSanitizer
{
    /** False when the file is not an SVG that can be read. */
    public static function sanitizeInPlace(UploadedFile $file): bool
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize((string) file_get_contents($file->getRealPath()));

        if ($clean === false || trim($clean) === '') {
            return false;
        }

        return file_put_contents($file->getRealPath(), $clean) !== false;
    }
}
