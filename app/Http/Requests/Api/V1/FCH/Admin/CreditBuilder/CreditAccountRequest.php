<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CreditAccountRequest extends BaseApiRequest
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
            'difficulty_tier' => 'nullable|string|max:10',
            'engine_eligibility_band' => 'nullable|string|max:255',
            'approval_level' => 'nullable|string|max:255',
            'typical_personal_fico_min' => 'nullable|integer',
            'typical_personal_fico_ideal' => 'nullable|integer',
            'time_in_business_min_months' => 'nullable|integer',
            'revenue_expectation' => 'nullable|string|max:255',
            'business_credit_required' => 'nullable|string|max:255',
            'pg_required' => 'nullable|string|max:255',
            'cash_based' => 'nullable|string|max:255',
            'velocity_sensitive' => 'nullable|string|max:255',
            'relationship_required' => 'nullable|string|max:255',
            'internal_notes' => 'nullable|string',
            'portal_tier_unlock' => 'nullable|string|max:255',
            'borrower_exposure' => 'nullable|string|max:255',
            'sequencing_priority' => 'nullable|string|max:255',
            'image_url' => 'nullable|string|max:500',
            'reference_url' => 'nullable|string|max:500',
            'status' => 'required|string',
        ];
    }
}
