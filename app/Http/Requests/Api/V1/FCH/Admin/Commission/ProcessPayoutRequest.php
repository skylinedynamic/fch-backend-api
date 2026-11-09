<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Commission;

use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPayoutRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partner_payment_method' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            // A payout goes through no gateway, so the host says whether it was
            // real money or a test - it is the payment's environment.
            'environment' => ['required', Rule::in(PaymentProvider::MODES)],
        ];
    }
}
