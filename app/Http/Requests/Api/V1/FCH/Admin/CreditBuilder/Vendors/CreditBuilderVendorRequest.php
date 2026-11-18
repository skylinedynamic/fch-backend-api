<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Vendors;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Support\Files\Disk;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Validator;

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
            // Any name a picture has there - spaces, (1), extra dots (R3-BUG-012) -
            // as long as it is a picture; never a URL, an absolute path, a
            // backslash or a step up (..).
            'logo' => ['nullable', 'string', 'max:255', 'regex:/\.(jpe?g|png|gif|svg|webp)$/i', 'not_regex:#(\.\.|://|\\\\|^/)#'],
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

    /**
     * A new logo has to be a file the File Manager holds (R3-BUG-012: any path
     * was taken). The logo a vendor already has is not checked again, so one
     * whose file is gone (seeded, or removed) still saves.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $logo = $this->input('logo');
            if (!is_string($logo) || $logo === '' || $validator->errors()->has('logo')) {
                return;
            }

            $current = $this->route('id')
                ? CreditBuilderVendor::whereKey($this->route('id'))->value('logo')
                : null;

            if ($logo !== $current && !Storage::disk(Disk::UPLOADS)->exists($logo)) {
                $validator->errors()->add('logo', __('credit_builder.validation_vendor_logo_missing'));
            }
        });
    }
}
