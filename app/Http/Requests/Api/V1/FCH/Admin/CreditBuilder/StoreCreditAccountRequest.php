<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreCreditAccountRequest extends BaseApiRequest
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
            'card_name' => 'required|string|max:255',
            'issuer' => 'required|string|max:255',
            'credit_category' => 'nullable|string|max:255',
            'difficulty_rank' => 'nullable|integer',
            'engine_eligibility_band' => 'nullable|string|max:255',
            'approval_level' => 'nullable|string|max:255',
            'typical_personal_fico_min' => 'required|integer',
            'typical_personal_fico_ideal' => 'required|integer',
            'time_in_business_min_months' => 'required|integer',
            'revenue_expectation' => 'nullable|string|max:255',
            'business_credit_required' => 'nullable|string|max:255',
            'pg_required' => 'nullable|string|max:255',
            'cash_based' => 'nullable|string|max:255',
            'velocity_sensitive' => 'nullable|string|max:255',
            'relationship_required' => 'nullable|string|max:255',
            'portal_tier_unlock' => 'nullable|string|max:255',
            'borrower_exposure' => 'nullable|string|max:255',
            'sequencing_priority' => 'nullable|string|max:255',
            'image_url' => 'required|string|max:500',
            'reference_url' => 'nullable|string|max:500',
            'status' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'card_name.required' => __('credit_account.card_name_required'),
            'issuer.required' => __('credit_account.issuer_required'),
            'image_url.required' => __('credit_account.image_required'),
            'typical_personal_fico_min.required' => __('credit_account.fico_min_required'),
            'typical_personal_fico_ideal.required' => __('credit_account.fico_ideal_required'),
            'time_in_business_min_months.required' => __('credit_account.biz_min_months_required'),
        ];
    }
}
