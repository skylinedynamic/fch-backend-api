<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Helpers\ApiResponse;

class StoreUpdatePartnerSubscriptionRequest extends BaseApiRequest
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
            'partner_id' => 'required|exists:partners,id',
            'partner_url' => 'nullable|string',
            'billing_term' => 'nullable|string|in:monthly,annual',
            'first_reg_date' => 'nullable|date',
            'first_reg_amount' => 'nullable|numeric',
            'first_payment_amount' => 'nullable|numeric',
            'first_reg_amount_2' => 'nullable|numeric',
            'recurring_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'subscription_start_date' => 'nullable|date',
            'subscription_end_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'termination_date' => 'nullable|date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'partner_id.required' => __('partner.validation.partner_id.required'),
            'partner_id.exists' => __('partner.validation.partner_id.exists'),
            'partner_url.string' => __('partner.validation.partner_url.string'),
            'billing_term.string' => __('partner.validation.billing_term.string'),
            'billing_term.in' => __('partner.validation.billing_term.in'),
            'first_reg_date.date' => __('partner.validation.first_reg_date.date'),
            'first_reg_amount.numeric' => __('partner.validation.first_reg_amount.numeric'),
            'first_payment_amount.numeric' => __('partner.validation.first_payment_amount.numeric'),
            'first_reg_amount_2.numeric' => __('partner.validation.first_reg_amount_2.numeric'),
            'recurring_amount.numeric' => __('partner.validation.recurring_amount.numeric'),
            'total_amount.numeric' => __('partner.validation.total_amount.numeric'),
            'subscription_start_date.date' => __('partner.validation.subscription_start_date.date'),
            'subscription_end_date.date' => __('partner.validation.subscription_end_date.date'),
            'expiry_date.date' => __('partner.validation.expiry_date.date'),
            'termination_date.date' => __('partner.validation.termination_date.date'),
        ];
    }
}
