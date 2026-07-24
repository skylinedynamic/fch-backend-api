<?php

namespace App\Http\Requests\Api\V1\GlobalPayment;

use App\Http\Requests\Api\V1\BaseApiRequest;

class ProcessPaymentRequest extends BaseApiRequest
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
            'provider_id' => 'required|integer|exists:payment_providers,id',
            'invoice_id' => 'required|integer|exists:invoices,id',
            'payment_method_id' => 'required|string',
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
            'provider_id.integer' => __('payment_validation.provider_id_integer'),
            'provider_id.exists' => __('payment_validation.provider_id_exists'),
            'invoice_id.required' => __('payment_validation.invoice_id_required'),
            'invoice_id.integer' => __('payment_validation.invoice_id_integer'),
            'invoice_id.exists' => __('payment_validation.invoice_id_exists'),
            'payment_method_id.required' => __('payment_validation.payment_method_id_required'),
            'payment_method_id.string' => __('payment_validation.payment_method_id_string'),
        ];
    }
}
