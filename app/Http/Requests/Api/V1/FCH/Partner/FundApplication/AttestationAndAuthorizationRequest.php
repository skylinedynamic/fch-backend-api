<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Rules\VisibleToUser;

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
            'id' => ['nullable', new VisibleToUser(FundingApplication::class)],
            'funding_request_id' => ['nullable', 'integer', new VisibleToUser(FundingRequest::class)],
            'signature_name' => 'required|string|min:3|max:255|regex:/^(?!\s*$).+/',
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
            'signature_name.required' => __('borrower_validation.signature_name_required'),
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

