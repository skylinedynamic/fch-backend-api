<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreUpdateSubscriptionRequest extends BaseApiRequest
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
        $rules = [
            'partner_id' => 'nullable|exists:partners,id',
            'borrower_id' => 'nullable|exists:borrowers,id',
            'partner_type' => 'nullable|string|max:255',
            'enrollment_type' => 'nullable|string|max:255',
            'monthly_platform_fee' => 'nullable|numeric|min:0',
            'total_subscription_amount' => 'nullable|numeric|min:0',
            'subscription_amount_per_month' => 'nullable|numeric|min:0',
            'payable_amount' => 'nullable|numeric|min:0',
            'installment_type' => 'nullable|in:0,1',
            'first_payment_date' => 'nullable|date',
            'platform_fee_start_date' => 'nullable|date',
            'total_paid_amount' => 'nullable|numeric|min:0',
            'subscription_start_date' => 'nullable|date',
            'subscription_end_date' => 'nullable|date|after_or_equal:subscription_start_date',
            'expiry_date' => 'nullable|date',
            'termination_date' => 'nullable|date',
            'status' => 'nullable|string',
        ];

        return $rules;
    }
}
