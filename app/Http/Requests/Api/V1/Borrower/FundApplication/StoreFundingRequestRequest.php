<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

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
            'id' => 'required|exists:funding_applications,id',
            'requested_amount' => 'required|numeric|min:0',
            'purpose_of_funds' => 'required|string|max:500',
            'preferred_funding_type' => 'required|string|max:255',
            'timeframe_needed' => 'required|string|max:255',
            'collateral_available' => 'required|string|max:500',
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
            'preferred_funding_type.string' => __('borrower_validation.preferred_funding_type_string'),
            'preferred_funding_type.max' => __('borrower_validation.preferred_funding_type_max'),
            'timeframe_needed.string' => __('borrower_validation.timeframe_needed_string'),
            'timeframe_needed.max' => __('borrower_validation.timeframe_needed_max'),
            'collateral_available.string' => __('borrower_validation.collateral_available_string'),
            'collateral_available.max' => __('borrower_validation.collateral_available_max'),
        ];
    }
}
