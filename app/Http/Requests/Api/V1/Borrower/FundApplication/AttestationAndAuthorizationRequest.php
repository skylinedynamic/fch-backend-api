<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

class AttestationAndAuthorizationRequest extends BaseApiRequest
{
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
            'id' => 'required|exists:funding_applications,id',
            'attestation_authorization' => 'required|accepted',
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
            'attestation_authorization.accepted' => __('borrower_validation.attestation_authorization_accepted'),
        ];
    }
}
