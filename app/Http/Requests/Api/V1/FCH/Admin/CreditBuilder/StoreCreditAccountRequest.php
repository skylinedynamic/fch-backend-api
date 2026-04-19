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
            'typical_personal_fico_min' => 'required|regex:/^[0-9]+$/|gt:0',
            'time_in_business_min_months' => 'required|regex:/^[0-9]+$/|gt:0',
            'image_url' => ['required', $this->hasFile('image_url') ? 'image|mimes:jpg,jpeg,png,webp,avif|max:2048' : 'string'],
            'reference_url' => 'nullable|url|max:500',
            'status' => 'nullable|string',
            'personal_guarantee' => 'nullable|string|max:255',
            'business_credit_profile' => 'nullable|string|max:255',
            'final_approval' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'card_name.required' => __('credit_account.card_name_required'),
            'issuer.required' => __('credit_account.issuer_required'),
            'image_url.required' => __('credit_account.image_required'),
            'image_url.mimes' => __('credit_account.image_mimes'),
            'typical_personal_fico_min.required' => __('credit_account.fico_min_required'),
            'typical_personal_fico_min.regex' => __('credit_account.fico_min_integer'),
            'typical_personal_fico_min.gt' => __('credit_account.fico_min_gt'),
            'time_in_business_min_months.required' => __('credit_account.biz_min_months_required'),
            'time_in_business_min_months.regex' => __('credit_account.biz_min_months_integer'),
            'time_in_business_min_months.gt' => __('credit_account.biz_min_months_gt'),
            'reference_url.url' => __('credit_account.reference_url_invalid'),
            'image_url.max' => __('credit_account.image_max'),
            'personal_guarantee.max' => __('credit_account.personal_guarantee_max'),
            'business_credit_profile.max' => __('credit_account.business_credit_profile_max'),
            'final_approval.max' => __('credit_account.final_approval_max'),
        ];
    }
}
