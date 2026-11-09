<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\Files\Disk;

/**
 * The file a borrower picked in the file manager to sign with.
 *
 * Checked here, on the server, rather than trusted from the browser: the file
 * has to be the borrower's own, really be an image (by its contents, not its
 * extension - any image type), and be within the size limit. Every failure is reported on
 * `signature_image`, the field the signature box shows its error under.
 *
 * The limits live in config/funding_application.php under `signature`.
 */
class SignatureImageRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.required' => __('borrower_validation.signature_image_required'),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                // Reported where the signature box looks for it.
                $validator->errors()->add('signature_image', $validator->errors()->first());

                return;
            }

            if ($message = $this->signatureFileError()) {
                $validator->errors()->add('signature_image', $message);
            }
        });
    }

    /**
     * Where the picked file lives on the public disk: inside the borrower's own
     * file manager folder, and nowhere else.
     */
    public function storagePath(): string
    {
        $relative = trim(str_replace(['..', '\\'], ['', '/'], (string) $this->input('path')), '/');

        return 'borrowers/' . Auth::user()->entity_id . '/' . $relative;
    }

    /**
     * Why this file cannot be used as a signature, or null when it can.
     */
    private function signatureFileError(): ?string
    {
        $disk = Storage::disk(Disk::UPLOADS);
        $path = $this->storagePath();
        $name = basename($path);

        if (! $disk->exists($path) || $disk->directoryExists($path)) {
            return __('borrower_validation.signature_file_not_found');
        }

        $allowed = (array) config('funding_application.signature.mime_types', ['image/*']);
        $maxKb = (int) config('funding_application.signature.max_file_kb', 1024);

        // The type is read from the file itself, never from its name: a renamed
        // PDF is still a PDF, and an AVIF saved as ".JPEG" is still an image.
        $contents = $disk->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'unknown';

        if (! Str::is($allowed, $mime)) {
            return __('borrower_validation.signature_file_type', ['file' => $name]);
        }

        $sizeKb = strlen($contents) / 1024;

        if ($sizeKb > $maxKb) {
            return __('borrower_validation.signature_file_size', [
                'file' => $name,
                'max' => self::readableSize($maxKb),
                'size' => self::readableSize($sizeKb),
            ]);
        }

        return null;
    }

    private static function readableSize(float $kb): string
    {
        return $kb >= 1024
            ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.') . ' MB'
            : max(1, (int) round($kb)) . ' KB';
    }
}
