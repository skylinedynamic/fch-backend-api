<?php

namespace App\Http\Requests\Api\V1\GlobalPayment;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Validation\Validator;

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
     * E2E-017: never accept raw card data. Card payments go through a gateway's
     * own tokenisation (Stripe Elements); the other methods are manual and take
     * only a reference number, so a card number here can only be a mistake or
     * an attack - and must not reach the payment record or the logs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $token = json_decode((string) $this->input('payment_token'), true);
            if (is_array($token)) {
                $rawCardKeys = ['cardNumber', 'card_number', 'cardCode', 'card_code', 'cvv', 'cvc', 'expMonth', 'exp_month', 'expYear', 'exp_year', 'expiry'];
                if (array_intersect(array_keys($token), $rawCardKeys)) {
                    $validator->errors()->add('payment_token', __('payment_validation.raw_card_data_not_accepted'));
                }
            }
        });
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
