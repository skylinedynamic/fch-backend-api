<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\PaymentMethod\PaymentMethod;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Validation\Rule;

class StorePartnerPaymentProviderRequest extends BaseApiRequest
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
            'id' => 'integer|exists:partner_payment_providers,id',
            'payment_provider_id' => 'required|exists:payment_providers,id',
            'payment_mode' => ['required',Rule::in([PaymentProvider::MODE_PRODUCTION, PaymentProvider::MODE_SANDBOX])],
            'status' => 'boolean',
            'is_default' => 'boolean',
            'configuration' => 'nullable|array',
        ];
    }
}
