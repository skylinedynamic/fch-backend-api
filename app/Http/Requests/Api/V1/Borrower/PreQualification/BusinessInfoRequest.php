<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class BusinessInfoRequest extends BaseApiRequest
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
            'id' => ['nullable','exists:pre_qualification_requests,id'],
            'business_name' => ['required','string','max:255'],
            'industry_type' => ['required','string','max:255'],
            'business_structure' => ['required','string','max:255'],
            'monthly_revenue' => ['required','numeric','min:0'],
            'annual_revenue' => ['required','numeric','min:0'],
            'years_in_business' => ['required','string','max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => __('borrower_validation.business_name_required'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),

            'industry_type.required' => __('borrower_validation.business_industry_type_required'),
            'industry_type.string' => __('borrower_validation.business_industry_type_string'),
            'industry_type.max' => __('borrower_validation.business_industry_type_max'),

            'business_structure.required' => __('borrower_validation.business_structure_required'),
            'business_structure.string' => __('borrower_validation.business_structure_string'),
            'business_structure.max' => __('borrower_validation.business_structure_max'),

            'monthly_revenue.required' => __('borrower_validation.monthly_revenue_required'),
            'monthly_revenue.numeric' => __('borrower_validation.monthly_revenue_numeric'),
            'monthly_revenue.min' => __('borrower_validation.monthly_revenue_min'),

            'annual_revenue.required' => __('borrower_validation.annual_revenue_required'),
            'annual_revenue.numeric' => __('borrower_validation.annual_revenue_numeric'),
            'annual_revenue.min' => __('borrower_validation.annual_revenue_min'),

            'years_in_business.required' => __('borrower_validation.years_in_business_required'),
            'years_in_business.string' => __('borrower_validation.years_in_business_string'),
            'years_in_business.max' => __('borrower_validation.years_in_business_max'),
        ];
    }
}
