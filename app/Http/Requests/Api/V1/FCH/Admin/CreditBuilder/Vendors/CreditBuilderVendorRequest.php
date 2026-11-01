<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Vendors;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CreditBuilderVendorRequest extends BaseApiRequest
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
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'requirements' => 'nullable|array',
            'benefits' => 'nullable|array',
            'report_to' => 'nullable|array',
            'tier_id' => 'required|exists:tiers,id',
            'status' => 'required|string',
            'description' => 'nullable|string',
            'general_url' => 'required|string',
            'affiliate_url' => 'nullable|string',
            // A path in the File Manager's storage (vendors/uline.png), not an upload.
            'logo' => ['nullable', 'string', 'max:255', 'regex:/^[\w\-\/ ]+\.(jpe?g|png|gif|svg|webp)$/i', 'not_regex:/\.\./'],
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
            'name.required' => __('credit_builder.validation_vendor_name_required'),
            'name.max' => __('credit_builder.validation_vendor_name_max'),
            'category.max' => __('credit_builder.validation_vendor_category_max'),
            'tier_id.required' => __('credit_builder.validation_vendor_tier_id_required'),
            'tier_id.exists' => __('credit_builder.validation_vendor_tier_id_exists'),
            'status.required' => __('credit_builder.validation_vendor_status_required'),
            'general_url.required' => __('credit_builder.validation_vendor_general_url_required'),
            'logo.regex' => __('credit_builder.validation_vendor_logo'),
            'logo.not_regex' => __('credit_builder.validation_vendor_logo'),
        ];
    }
}
