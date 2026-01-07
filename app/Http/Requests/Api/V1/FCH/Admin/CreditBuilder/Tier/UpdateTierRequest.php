<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Tier;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateTierRequest extends BaseApiRequest
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
            'name' => 'sometimes|string|max:255',
            'title' => 'sometimes|string|max:255',
            'subtitle' => 'nullable|string',
            'has_vendor' => 'nullable|boolean',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.max' => __('tier.validation_name_max'),
            'title.max' => __('tier.validation_title_max'),
            'status.max' => __('tier.validation_status_max'),
        ];
    }
}
