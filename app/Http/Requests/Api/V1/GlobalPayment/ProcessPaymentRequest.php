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
            'payment_method_id' => 'required|integer|exists:partner_payment_providers,payment_provider_id',
            'invoice_id' => 'required|integer|exists:invoices,id',
            'payment_token' => 'required|string',
            'bank_detail_id' => 'nullable|integer|exists:payment_method_bank_details,id',
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
            'payment_method_id.required' => __('payment_validation.payment_method_id_required'),
            'payment_method_id.integer' => __('payment_validation.payment_method_id_integer'),
            'payment_method_id.exists' => __('payment_validation.payment_method_id_exists'),
            'invoice_id.required' => __('payment_validation.invoice_id_required'),
            'invoice_id.integer' => __('payment_validation.invoice_id_integer'),
            'invoice_id.exists' => __('payment_validation.invoice_id_exists'),
            'payment_token.required' => __('payment_validation.payment_token_required'),
            'payment_token.string' => __('payment_validation.payment_token_string'),
            'bank_detail_id.integer' => __('payment_validation.bank_detail_id_integer'),
            'bank_detail_id.exists' => __('payment_validation.bank_detail_id_exists'),
        ];
    }
}
