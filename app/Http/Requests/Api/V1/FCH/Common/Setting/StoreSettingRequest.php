<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreSettingRequest extends BaseApiRequest
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
            'payment_mode' => 'nullable|string|in:sandbox,production',
            'stripe_key_sandbox' => 'nullable|string',
            'stripe_secret_sandbox' => 'nullable|string',
            'stripe_key_production' => 'nullable|string',
            'stripe_secret_production' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'configuration' => 'nullable|array',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_mode.in' => __('setting_validation.payment_mode_in'),
            'stripe_key_sandbox.string' => __('setting_validation.stripe_key_sandbox_string'),
            'stripe_secret_sandbox.string' => __('setting_validation.stripe_secret_sandbox_string'),
            'stripe_key_production.string' => __('setting_validation.stripe_key_production_string'),
            'stripe_secret_production.string' => __('setting_validation.stripe_secret_production_string'),
            'payment_method.string' => __('setting_validation.payment_method_string'),
            'configuration.array' => __('setting_validation.configuration_array'),
        ];
    }
}
