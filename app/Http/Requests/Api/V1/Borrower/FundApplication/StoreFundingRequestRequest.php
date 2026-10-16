<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreFundingRequestRequest extends BaseApiRequest
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
            'funding_request_id' => 'nullable',
            'funding_sequence_id' => 'nullable',
            'requested_amount' => 'required|numeric|min:0',
            'purpose_of_funds' => 'required|string|max:500',
            'approved_funding' => 'required|string|max:255',
            'purpose_note' => 'nullable|string|max:500',
            'preferred_funding_type' => 'required|string|max:255',
            // The other funding types the applicant would also consider - never
            // the preferred one again, each once, from the same list.
            'additional_funding_types' => ['nullable', 'array'],
            'additional_funding_types.*' => [
                'string',
                'distinct',
                Rule::in(config('funding_application.options.funding_types', [])),
                'different:preferred_funding_type',
            ],
            'timeframe_needed' => 'nullable|string|max:255',
            'collateral_available' => 'nullable|string|max:500',
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
            'requested_amount.numeric' => __('borrower_validation.requested_amount_numeric'),
            'requested_amount.min' => __('borrower_validation.requested_amount_min'),
            'purpose_of_funds.string' => __('borrower_validation.purpose_of_funds_string'),
            'purpose_of_funds.max' => __('borrower_validation.purpose_of_funds_max'),
            'approved_funding.string' => __('borrower_validation.approved_funding_string'),
            'approved_funding.max' => __('borrower_validation.approved_funding_max'),
            'purpose_note.string' => __('borrower_validation.purpose_note_string'),
            'purpose_note.max' => __('borrower_validation.purpose_note_max'),
            'preferred_funding_type.string' => __('borrower_validation.preferred_funding_type_string'),
            'preferred_funding_type.max' => __('borrower_validation.preferred_funding_type_max'),
            'additional_funding_types.*.in' => __('borrower_validation.additional_funding_type_invalid'),
            'additional_funding_types.*.distinct' => __('borrower_validation.additional_funding_type_invalid'),
            'additional_funding_types.*.different' => __('borrower_validation.additional_funding_type_preferred'),
            'timeframe_needed.string' => __('borrower_validation.timeframe_needed_string'),
            'timeframe_needed.max' => __('borrower_validation.timeframe_needed_max'),
            'collateral_available.string' => __('borrower_validation.collateral_available_string'),
            'collateral_available.max' => __('borrower_validation.collateral_available_max'),
        ];
    }
}
