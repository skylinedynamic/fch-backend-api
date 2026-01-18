<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Setting;

use Illuminate\Foundation\Http\FormRequest;

class StoreSettingRequest extends FormRequest
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
            'card_name_sandbox' => 'nullable|string',
            'card_no_sandbox' => 'nullable|string',
            'card_expiry_sandbox' => 'nullable|string',
            'card_cvv_sandbox' => 'nullable|string',
            'card_name_production' => 'nullable|string',
            'card_no_production' => 'nullable|string',
            'card_expiry_production' => 'nullable|string',
            'card_cvv_production' => 'nullable|string',
            'currency' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'payment_mode' => 'nullable|string|in:sandbox,production',
            'stripe_key_sandbox' => 'nullable|string',
            'stripe_secret_sandbox' => 'nullable|string',
            'stripe_key_production' => 'nullable|string',
            'stripe_secret_production' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'configuration' => 'nullable|array',
            'partner_id' => 'nullable|integer|exists:partners,id',
        ];
    }
}
