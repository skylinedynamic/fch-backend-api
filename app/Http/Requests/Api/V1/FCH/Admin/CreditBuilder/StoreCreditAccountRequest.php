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
            'typical_personal_fico_min' => 'required|integer',
            'time_in_business_min_months' => 'required|integer',
            'image_url' => 'required|string|max:500',
            'reference_url' => 'nullable|string|max:500',
            'status' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'card_name.required' => __('credit_account.card_name_required'),
            'issuer.required' => __('credit_account.issuer_required'),
            'image_url.required' => __('credit_account.image_required'),
            'typical_personal_fico_min.required' => __('credit_account.fico_min_required'),
            'time_in_business_min_months.required' => __('credit_account.biz_min_months_required'),
        ];
    }
}
