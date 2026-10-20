<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Lead\Lead;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Validation\Rule;
use Str;

class ConvertLeadRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the lead type from the route-bound lead.
     */
    private function getLeadType(): ?string
    {
        return $this->lead()?->lead_type;
    }

    private function lead(): ?Lead
    {
        return Lead::find($this->route('id'));
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $leadType = $this->getLeadType();
        $isFreeTrail = $this->input('is_free_trail') == 1 || $this->input('is_free_trail') === true || $this->input('is_free_trail') === 'true';

        if ($leadType === Lead::BORROWER_TYPE_LEAD) {
            return $this->borrowerRules($isFreeTrail);
        }

        if ($leadType === Lead::PARTNER_TYPE_LEAD) {
            return $this->partnerRules($isFreeTrail);
        }

        // Fallback: only require plan_id
        return [
            'is_free_trail' => ['nullable', 'boolean'],
            'plan_id' => [$isFreeTrail ? 'nullable' : 'required', 'exists:subscription_plans,id'],
        ];
    }

    /**
     * Validation rules when converting to a Borrower.
     * Frontend sends enrollment_amount — mapped to enrollment_payment internally.
     */
    private function borrowerRules(bool $isFreeTrail): array
    {
        $rules = [
            // The host converting another partner's lead says whose borrower it becomes
            'convert_as' => [
                $this->lead()?->offersOwnerChoiceTo($this->user()) ? 'required' : 'nullable',
                Rule::in(Lead::CONVERT_AS),
            ],
            'is_free_trail' => ['nullable', 'boolean'],
            'plan_id' => [$isFreeTrail ? 'nullable' : 'required', 'exists:subscription_plans,id'],
            'enrollment_type' => [$isFreeTrail ? 'nullable' : 'required', 'string', 'max:255'],
            'enrollment_amount' => [$isFreeTrail ? 'nullable' : 'required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'],
            'free_trail_end_date' => ['nullable'],
        ];

        $planId = $this->input('plan_id');
        $plan = $planId ? SubscriptionPlan::find($planId) : null;
        $isInstallment = false;
        if ($plan) {
            $isInstallment = ($plan->total_installment_count_upto > 1)
                || (strtolower(trim((string) $plan->enrollment_type)) === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG);
        } else {
            $enrollmentType = strtolower(trim((string) $this->input('enrollment_type')));
            $isInstallment = ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG);
        }

        if ($isInstallment) {
            $rules['installment_down_payment'] = ['nullable', 'numeric', 'min:0'];
            $rules['installment_months'] = ['nullable', 'integer', 'min:1', 'max:24'];
            $rules['installment_monthly_amount'] = ['nullable', 'numeric', 'min:0'];
            $rules['installment_remaining_balance'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Validation rules when converting to a Partner.
     * Mirrors StorePartnerRequest financial rules.
     */
    private function partnerRules(bool $isFreeTrail): array
    {
        $rules = [
            'is_free_trail' => ['nullable', 'boolean'],
            'plan_id' => [$isFreeTrail ? 'nullable' : 'required', 'exists:subscription_plans,id'],
            'partner_type' => ['required', 'string', 'max:255'],
            'enrollment_type' => [$isFreeTrail ? 'nullable' : 'required', 'string', 'max:255'],
            'enrollment_amount' => [$isFreeTrail ? 'nullable' : 'required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'],
            'monthly_platform_fee' => [$isFreeTrail ? 'nullable' : 'required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'],
            'free_trail_end_date' => ['nullable'],
        ];

        $planId = $this->input('plan_id');
        $plan = $planId ? SubscriptionPlan::find($planId) : null;
        $isInstallment = false;
        if ($plan) {
            $isInstallment = ($plan->total_installment_count_upto > 1)
                || (strtolower(trim((string) $plan->enrollment_type)) === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG);
        } else {
            $enrollmentType = strtolower(trim((string) $this->input('enrollment_type')));
            $isInstallment = ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG);
        }

        if ($isInstallment) {
            $rules['installment_down_payment'] = ['nullable', 'numeric', 'min:0'];
            $rules['installment_months'] = ['nullable', 'integer', 'min:1', 'max:24'];
            $rules['installment_monthly_amount'] = ['nullable', 'numeric', 'min:0'];
            $rules['installment_remaining_balance'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            // Shared
            'plan_id.required' => __('borrower_validation.plan_id_required'),
            'plan_id.exists' => __('borrower_validation.plan_id_exists'),
            'convert_as.required' => __('lead.convert_as_required'),
            'convert_as.in' => __('lead.convert_as_required'),

            // Borrower — field name is enrollment_amount (mapped internally to enrollment_payment)
            'enrollment_amount.required' => __('partner.validation.enrollment_amount.required'),
            'enrollment_amount.gt' => __('partner.validation.enrollment_amount.gt'),
            'enrollment_amount.regex' => 'Enrollment amount must be a number greater than 0 and cannot be -0.',

            // Partner
            'partner_type.required' => __('lead.partner_type_required'),
            'enrollment_type.required' => __('lead.enrollment_type_required'),
            'monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee.required'),
            'monthly_platform_fee.numeric' => __('partner.validation.monthly_platform_fee.numeric'),
            'monthly_platform_fee.gt' => __('partner.validation.monthly_platform_fee.gt'),
            'monthly_platform_fee.regex' => 'Monthly platform fee must be a number greater than 0 and cannot be -0.',
        ];
    }
}
