<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Lead\Lead;

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
        $id = $this->route('id');
        $lead = Lead::find($id);
        return $lead?->lead_type;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $leadType = $this->getLeadType();

        if ($leadType === 'Borrower') {
            return $this->borrowerRules();
        }

        if ($leadType === 'Partner') {
            return $this->partnerRules();
        }

        // Fallback: only require plan_id
        return [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
        ];
    }

    /**
     * Validation rules when converting to a Borrower.
     * Frontend sends enrollment_amount — mapped to enrollment_payment internally.
     */
    private function borrowerRules(): array
    {
        return [
            'plan_id'           => ['required', 'exists:subscription_plans,id'],
            'enrollment_amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Validation rules when converting to a Partner.
     * Mirrors StorePartnerRequest financial rules.
     */
    private function partnerRules(): array
    {
        return [
            'plan_id'              => ['required', 'exists:subscription_plans,id'],
            'partner_type'         => ['required', 'string', 'max:255'],
            'enrollment_type'      => ['required', 'string', 'max:255'],
            'enrollment_amount'    => ['required', 'numeric', 'gt:0'],
            'monthly_platform_fee' => ['required', 'numeric', 'min:0'],  // 0 is valid for some plans
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            // Shared
            'plan_id.required'              => __('borrower_validation.plan_id_required'),
            'plan_id.exists'                => __('borrower_validation.plan_id_exists'),

            // Borrower — field name is enrollment_amount (mapped internally to enrollment_payment)
            'enrollment_amount.required'    => __('partner.validation.enrollment_amount.required'),
            'enrollment_amount.numeric'     => __('partner.validation.enrollment_amount.numeric'),
            'enrollment_amount.gt'          => __('partner.validation.enrollment_amount.gt'),

            // Partner
            'partner_type.required'         => __('partner.validation.partner_type.required'),
            'enrollment_type.required'      => __('partner.validation.enrollment_type.required'),
            'monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee.required'),
            'monthly_platform_fee.numeric'  => __('partner.validation.monthly_platform_fee.numeric'),
            'monthly_platform_fee.min'      => 'Monthly platform fee cannot be negative.',
        ];
    }
}
