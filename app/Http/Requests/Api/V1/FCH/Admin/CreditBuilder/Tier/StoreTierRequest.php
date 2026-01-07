<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Tier;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreTierRequest extends BaseApiRequest
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
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string',
            'description' => 'nullable|string',
            'has_vendor' => 'nullable|boolean',
            'status' => 'nullable|string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => __('tier.validation_name_required'),
            'name.max' => __('tier.validation_name_max'),
            'title.required' => __('tier.validation_title_required'),
            'title.max' => __('tier.validation_title_max'),
            'status.max' => __('tier.validation_status_max'),
        ];
    }
}
