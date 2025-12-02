<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FundingInfoRequest extends BaseApiRequest
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
            'funding_amount' => ['required','numeric','min:0'],
            'funding_term' => ['required','string','max:255'],
            'funding_purpose' => ['required','string','max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'funding_amount.required' => __('borrower_validation.funding_amount_required'),
            'funding_amount.numeric' => __('borrower_validation.funding_amount_numeric'),
            'funding_amount.min' => __('borrower_validation.funding_amount_min'),

            'funding_term.required' => __('borrower_validation.funding_term_required'),
            'funding_term.string' => __('borrower_validation.funding_term_string'),
            'funding_term.max' => __('borrower_validation.funding_term_max'),

            'funding_purpose.required' => __('borrower_validation.funding_purpose_required'),
            'funding_purpose.string' => __('borrower_validation.funding_purpose_string'),
            'funding_purpose.max' => __('borrower_validation.funding_purpose_max'),
        ];
    }
}
