<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;

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
        $rules = [
            'partner_id' => 'required|exists:partners,id',
            'partner_type' => 'nullable|string|max:255',
            'enrollment_type' => 'nullable|string|max:255',
            'monthly_platform_fee' => 'nullable|numeric|min:0',
            'first_reg_amount' => 'nullable|numeric|min:0',
            'first_reg_date' => 'nullable|date',
            'installment_type' => 'nullable|in:0,1',
            'first_reg_payment' => 'nullable|numeric|min:0',
            'first_reg_payment_2' => 'nullable|numeric|min:0',
            'total_paid_amount' => 'nullable|numeric|min:0',
            'subscription_start_date' => 'nullable|date',
            'subscription_end_date' => 'nullable|date|after_or_equal:subscription_start_date',
            'expiry_date' => 'nullable|date',
            'termination_date' => 'nullable|date',
            'status' => 'nullable|string',
        ];

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'partner_id.required' => __('partner.validation.partner_id.required'),
            'partner_id.exists' => __('partner.validation.partner_id.exists'),

            'partner_type.string' => __('partner.validation.partner_type.string'),
            'partner_type.max' => __('partner.validation.partner_type.max'),

            'enrollment_type.string' => __('partner.validation.enrollment_type.string'),
            'enrollment_type.max' => __('partner.validation.enrollment_type.max'),

            'monthly_platform_fee.numeric' => __('partner.validation.monthly_platform_fee.numeric'),
            'monthly_platform_fee.min' => __('partner.validation.monthly_platform_fee.min'),

            'first_reg_amount.numeric' => __('partner.validation.first_reg_amount.numeric'),
            'first_reg_amount.min' => __('partner.validation.first_reg_amount.min'),

            'first_reg_date.date' => __('partner.validation.first_reg_date.date'),

            'installment_type.numeric' => __('partner.validation.installment_type.numeric'),

            'first_reg_payment.numeric' => __('partner.validation.first_reg_payment.numeric'),
            'first_reg_payment.min' => __('partner.validation.first_reg_payment.min'),

            'first_reg_payment_2.numeric' => __('partner.validation.first_reg_payment_2.numeric'),
            'first_reg_payment_2.min' => __('partner.validation.first_reg_payment_2.min'),

            'total_paid_amount.numeric' => __('partner.validation.total_paid_amount.numeric'),
            'total_paid_amount.min' => __('partner.validation.total_paid_amount.min'),

            'subscription_start_date.date' => __('partner.validation.subscription_start_date.date'),

            'subscription_end_date.date' => __('partner.validation.subscription_end_date.date'),
            'subscription_end_date.after_or_equal' => __('partner.validation.subscription_end_date.after_or_equal'),

            'expiry_date.date' => __('partner.validation.expiry_date.date'),
            'termination_date.date' => __('partner.validation.termination_date.date'),

            'status.string' => __('partner.validation.status.string'),
        ];
    }
}
