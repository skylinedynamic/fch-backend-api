<?php

namespace App\Http\Requests\Api\V1\GlobalPayment;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * Finishing a card payment after the payer's bank authentication (3-D Secure,
 * BUG-066). Only the payment id travels: the outcome is read from the gateway,
 * never taken from the browser.
 */
class ConfirmPaymentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_id' => 'required|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'payment_id.required' => __('payment_validation.payment_id_required'),
            'payment_id.integer' => __('payment_validation.payment_id_integer'),
        ];
    }
}
