<?php

namespace App\Http\Requests\Api\V1\Global\Stripe;

use Illuminate\Foundation\Http\FormRequest;

class StripePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
}
