<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Concerns\ValidatesCommissionTiers;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Partner profile › Commissions: the partner's Funding commission tiers by how
 * many of its clients' funding applications have been funded (0-10, 11-20, 21
 * and up). Each tier has its own commission type:
 * - Percentage (the default): its share (%) of the success fee, at most 99,
 *   since the commission split keeps at least 1% of the success fee for the
 *   admin (SaveCommissionRequest);
 * - Flat Amount: a fixed amount ($) per deal.
 * Only the tier's own type's value is validated and kept. Required: at least
 * one tier, the first from 0, each with its value.
 */
class UpdatePartnerCommissionRequest extends BaseApiRequest
{
    use ValidatesCommissionTiers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $flat = PartnerCommissionTier::TYPE_FLAT;

        return [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_count' => ['required', 'integer', 'min:0'],
            'tiers.*.max_count' => ['nullable', 'integer', 'min:0'],
            'tiers.*.commission_type' => ['nullable', 'string', Rule::in(PartnerCommissionTier::TYPES)],
            'tiers.*.percentage' => ["exclude_if:tiers.*.commission_type,{$flat}", 'required', 'numeric', 'min:' . Partner::COMMISSION_MIN, 'max:' . Partner::COMMISSION_MAX],
            'tiers.*.amount' => ["exclude_unless:tiers.*.commission_type,{$flat}", 'required', 'numeric', 'min:' . PartnerCommissionTier::AMOUNT_MIN, 'max:' . PartnerCommissionTier::AMOUNT_MAX],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateTierRanges($validator, $this->input('tiers'), 'tiers'));
    }

    public function messages(): array
    {
        return [
            'tiers.required' => __('partner.validation.commission.required_tiers'),
            'tiers.array' => __('partner.validation.commission.required_tiers'),
            'tiers.min' => __('partner.validation.commission.required_tiers'),
            'tiers.*.min_count.required' => __('commission.tier.min_count_required'),
            'tiers.*.min_count.integer' => __('commission.tier.min_count_integer'),
            'tiers.*.min_count.min' => __('commission.tier.min_count_min'),
            'tiers.*.max_count.integer' => __('commission.tier.max_count_integer'),
            'tiers.*.max_count.min' => __('commission.tier.max_count_min'),
            'tiers.*.commission_type.string' => __('partner.validation.commission.type'),
            'tiers.*.commission_type.in' => __('partner.validation.commission.type'),
            'tiers.*.percentage.required' => __('partner.validation.commission.required'),
            'tiers.*.percentage.numeric' => __('partner.validation.commission.numeric'),
            'tiers.*.percentage.min' => __('partner.validation.commission.min'),
            'tiers.*.percentage.max' => __('partner.validation.commission.max'),
            'tiers.*.amount.required' => __('partner.validation.commission.amount_required'),
            'tiers.*.amount.numeric' => __('partner.validation.commission.amount_numeric'),
            'tiers.*.amount.min' => __('partner.validation.commission.amount_min'),
            'tiers.*.amount.max' => __('partner.validation.commission.amount_max'),
        ];
    }
}
