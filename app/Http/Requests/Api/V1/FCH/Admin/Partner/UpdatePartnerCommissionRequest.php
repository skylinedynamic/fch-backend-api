<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Concerns\ValidatesCommissionTiers;
use App\Models\Partner\Partner;
use Illuminate\Contracts\Validation\Validator;

/**
 * Partner profile › Commissions: the partner's Funding commission tiers - its
 * share (%) of the success fee by how many of its clients' funding
 * applications have been funded (0-10, 11-20, 21 and up). Required: at least
 * one tier, the first from 0, each with its %. Each % is at most 99, since the
 * commission split keeps at least 1% of the success fee for the admin
 * (SaveCommissionRequest).
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
        return [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_count' => ['required', 'integer', 'min:0'],
            'tiers.*.max_count' => ['nullable', 'integer', 'min:0'],
            'tiers.*.percentage' => ['required', 'numeric', 'min:' . Partner::COMMISSION_MIN, 'max:' . Partner::COMMISSION_MAX],
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
            'tiers.*.percentage.required' => __('partner.validation.commission.required'),
            'tiers.*.percentage.numeric' => __('partner.validation.commission.numeric'),
            'tiers.*.percentage.min' => __('partner.validation.commission.min'),
            'tiers.*.percentage.max' => __('partner.validation.commission.max'),
        ];
    }
}
