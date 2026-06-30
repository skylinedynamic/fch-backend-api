<?php

namespace App\Http\Requests\Api\V1\Borrower\Funding;

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
            'funding_sequence_id' => 'required|exists:funding_sequencings,id',
            'funding_type' => 'nullable|string|max:255',
            'requested_amount' => 'required|numeric|min:0',
            'use_of_funds' => 'nullable|string|max:255',
            'documents_uploaded' => 'nullable|array',
            'documents_uploaded.*' => 'nullable|string',
            'estimated_speed' => 'nullable|string|max:255',
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
            'funding_sequence_id.required' => __('funding_request.funding_sequence_id_required'),
            'funding_sequence_id.exists' => __('funding_request.funding_sequence_id_exists'),
            'funding_type.string' => __('funding_request.funding_type_string'),
            'funding_type.max' => __('funding_request.funding_type_max'),
            'requested_amount.required' => __('borrower_validation.requested_amount_required'),
            'requested_amount.numeric' => __('borrower_validation.requested_amount_numeric'),
            'requested_amount.min' => __('borrower_validation.requested_amount_min'),
            'use_of_funds.string' => __('funding_request.use_of_funds_string'),
            'use_of_funds.max' => __('funding_request.use_of_funds_max'),
            'documents_uploaded.array' => __('funding_request.documents_uploaded_array'),
            'documents_uploaded.*.string' => __('funding_request.documents_uploaded_string'),
            'estimated_speed.string' => __('funding_request.estimated_speed_string'),
            'estimated_speed.max' => __('funding_request.estimated_speed_max'),
        ];
    }
}
