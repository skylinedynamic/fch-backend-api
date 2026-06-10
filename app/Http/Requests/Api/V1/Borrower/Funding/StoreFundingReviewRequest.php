<?php

namespace App\Http\Requests\Api\V1\Borrower\Funding;

use Illuminate\Foundation\Http\FormRequest;

class StoreFundingReviewRequest extends FormRequest
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
            'requested_amount.required' => __('borrower_validation.requested_amount_required'),
            'requested_amount.numeric' => __('borrower_validation.requested_amount_numeric'),
            'requested_amount.min' => __('borrower_validation.requested_amount_min'),
        ];
    }
}
