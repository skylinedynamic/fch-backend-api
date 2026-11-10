<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use Illuminate\Validation\Rule;

/**
 * The partner profile's Status & Agent tab. Only these two fields are taken, so
 * the save is never held up by the partner's other details (its phone, address,
 * ...), which belong to the Partner information save. The agent's email and
 * mobile on the tab are read-only and are not part of it.
 */
class UpdatePartnerStatusAgentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'    => ['required', 'string', Rule::in(array_values(Partner::STATUS))],
            'assign_to' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required'    => __('partner.validation.status.required'),
            'status.in'          => __('partner.validation.status.in'),
            'assign_to.required' => __('partner.validation.assign_to.required'),
            'assign_to.integer'  => __('partner.validation.assign_to.exists'),
            'assign_to.exists'   => __('partner.validation.assign_to.exists'),
        ];
    }
}
