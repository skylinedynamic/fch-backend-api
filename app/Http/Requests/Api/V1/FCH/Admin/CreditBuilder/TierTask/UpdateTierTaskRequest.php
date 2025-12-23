<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\TierTask;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateTierTaskRequest extends BaseApiRequest
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
            'tier_id' => 'sometimes|exists:tiers,id',
            'title' => 'sometimes|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'url' => 'nullable|string|url',
            'button_title' => 'nullable|string|max:255',
            'order' => 'nullable|integer',
            'estimated_completion_days' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'tier_id.exists' => __('tier_task.validation_tier_id_exists'),
            'title.max' => __('tier_task.validation_title_max'),
            'status.max' => __('tier_task.validation_status_max'),
        ];
    }
}
