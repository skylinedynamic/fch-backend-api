<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * A client asking us to refresh their credit profile.
 *
 * An update is a new credit pull, so it needs its own authorisation - consent is
 * per request, not once per client. The boxes are ticked again and a new record
 * is written, which is what lets each pull be tied to the agreement behind it.
 */
class RequestCreditProfileUpdateRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Refused server-side, not just in the browser - see
            // StoreCreditProfileRequestRequest for why.
            'credit_authorization_accepted' => ['required', 'accepted'],
            'e_consent_accepted' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_authorization_accepted.required' => __('credit_profile_request.credit_authorization_required'),
            'credit_authorization_accepted.accepted' => __('credit_profile_request.credit_authorization_required'),
            'e_consent_accepted.required' => __('credit_profile_request.e_consent_required'),
            'e_consent_accepted.accepted' => __('credit_profile_request.e_consent_required'),
        ];
    }
}
