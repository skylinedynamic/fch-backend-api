<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

class DisclosuresAndConsentRequest extends BaseApiRequest
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
            'id' => 'nullable|exists:funding_applications,id',
            'credit_pull_authorization' => 'required|accepted',
            'terms_conditions_acceptance' => 'required|accepted',
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
            'credit_pull_authorization.accepted' => __('borrower_validation.credit_pull_authorization_accepted'),
            'terms_conditions_acceptance.accepted' => __('borrower_validation.terms_conditions_acceptance_accepted'),
        ];
    }
}
