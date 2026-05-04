<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use App\Http\Requests\Api\V1\BaseApiRequest;

class GetSettingRequest extends BaseApiRequest
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
     */
    public function rules(): array
    {
        return [
            'key' => 'required|string',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'key.required' => __('setting_validation.key_required'),
        ];
    }
}
