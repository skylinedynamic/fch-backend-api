<?php

namespace App\Http\Requests\Api\V1\Global\Payment;

use App\Http\Requests\Api\V1\BaseApiRequest;

class PaymentRequest extends BaseApiRequest
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
            'amount' => 'nullable|numeric|min:1',
            'currency' => 'nullable|string|size:3',
            'payment_method_id' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'invoice_id' => 'nullable|string',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'borrower_id' => 'nullable|integer|exists:borrowers,id',
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|integer',
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
            'amount.numeric' => __('payment_validation.amount_numeric'),
            'amount.min' => __('payment_validation.amount_min'),
            'currency.string' => __('payment_validation.currency_string'),
            'currency.size' => __('payment_validation.currency_size'),
            'payment_method_id.string' => __('payment_validation.payment_method_id_string'),
            'description.string' => __('payment_validation.description_string'),
            'description.max' => __('payment_validation.description_max'),
            'invoice_id.string' => __('payment_validation.invoice_id_string'),
            'partner_id.integer' => __('payment_validation.partner_id_integer'),
            'partner_id.exists' => __('payment_validation.partner_id_exists'),
            'borrower_id.integer' => __('payment_validation.borrower_id_integer'),
            'borrower_id.exists' => __('payment_validation.borrower_id_exists'),
            'entity_type.string' => __('payment_validation.entity_type_string'),
            'entity_id.integer' => __('payment_validation.entity_id_integer'),
        ];
    }
}
