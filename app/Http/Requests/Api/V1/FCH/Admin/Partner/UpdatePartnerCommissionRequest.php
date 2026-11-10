<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;

/**
 * Partner profile › Commissions: the partner's share (%) of the success fee.
 * Blank clears it. At most 99%, since the commission split keeps at least 1%
 * of the success fee for the admin (SaveCommissionRequest).
 */
class UpdatePartnerCommissionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commission' => ['present', 'nullable', 'numeric', 'min:' . Partner::COMMISSION_MIN, 'max:' . Partner::COMMISSION_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            'commission.present' => __('partner.validation.commission.present'),
            'commission.numeric' => __('partner.validation.commission.numeric'),
            'commission.min' => __('partner.validation.commission.min'),
            'commission.max' => __('partner.validation.commission.max'),
        ];
    }
}
