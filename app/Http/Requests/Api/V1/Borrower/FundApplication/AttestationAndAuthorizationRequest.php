<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AttestationAndAuthorizationRequest extends BaseApiRequest
{
    // Characters of the data URL, not bytes of the image. The portal sends a
    // small PNG off a fixed-size pad, so this is generous.
    private const MAX_SIGNATURE_IMAGE_LENGTH = 300000;

    /**
     * A PNG or JPEG data URL that really decodes to an image.
     */
    private static function isSignatureImage(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        foreach (['data:image/png;base64,', 'data:image/jpeg;base64,'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $binary = base64_decode(substr($value, strlen($prefix)), true);

                return $binary !== false && @getimagesizefromstring($binary) !== false;
            }
        }

        return false;
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Signing always names the application it is for. Without either of
            // these the save would fall back to "the borrower's latest request"
            // and put the signature on whichever application that happens to be.
            // The id must also be one of the borrower's own applications.
            'id' => [
                'nullable',
                'required_without:funding_request_id',
                Rule::exists('funding_applications', 'id')->where('borrower_id', Auth::user()?->entity_id),
            ],
            'funding_request_id' => 'nullable|required_without:id|integer',
            // The borrower signs one way or the other: a typed full legal name,
            // or an e-signature (drawn or uploaded image). Whichever they did
            // not choose is not required and is ignored.
            'signature_type' => 'nullable|in:typed,esign',
            'signature_name' => 'required_unless:signature_type,esign|nullable|string|min:3|max:255|regex:/^(?!\s*$).+/',
            'signature_image' => [
                'required_if:signature_type,esign',
                'nullable',
                'string',
                'max:' . self::MAX_SIGNATURE_IMAGE_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! self::isSignatureImage($value)) {
                        $fail(__('borrower_validation.signature_image_invalid'));
                    }
                },
            ],
            // The date in the e-signature block. Never in the future; a day's
            // grace covers a signer whose timezone is ahead of the server's.
            'signature_date' => 'nullable|date_format:Y-m-d|before_or_equal:' . now()->addDay()->toDateString(),
            'esign_consent' => 'required|accepted',
            'terms_consent' => 'required|accepted',
            'credit_consent' => 'required|accepted',
            'legal_binding_consent' => 'required|accepted',
            'timezone' => 'required|string',
            'application_version' => 'nullable|string',
            'consents_data' => 'nullable|string',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => __('borrower_validation.funding_application_id_exists'),
            'id.required_without' => __('borrower_validation.funding_application_id_exists'),
            'funding_request_id.required_without' => __('borrower_validation.funding_application_id_exists'),
            'signature_name.required' => __('borrower_validation.signature_name_required'),
            'signature_name.required_unless' => __('borrower_validation.signature_name_required'),
            'signature_image.required_if' => __('borrower_validation.signature_image_required'),
            'signature_image.max' => __('borrower_validation.signature_image_invalid'),
            'signature_date.date_format' => __('borrower_validation.signature_date_invalid'),
            'signature_date.before_or_equal' => __('borrower_validation.signature_date_future'),
            'signature_name.min' => __('borrower_validation.signature_name_min'),
            'signature_name.max' => __('borrower_validation.signature_name_max'),
            'signature_name.regex' => __('borrower_validation.signature_name_invalid'),
            'esign_consent.accepted' => __('borrower_validation.esign_consent_accepted'),
            'terms_consent.accepted' => __('borrower_validation.terms_consent_accepted'),
            'credit_consent.accepted' => __('borrower_validation.credit_consent_accepted'),
            'legal_binding_consent.accepted' => __('borrower_validation.legal_binding_consent_accepted'),
        ];
    }
}
