<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Commission;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Validator;

class SaveCommissionRequest extends BaseApiRequest
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
     */
    public function rules(): array
    {
        return [
            'funding_application_id' => ['required', 'integer', 'exists:funding_applications,id'],
            'total_commission' => ['required', 'numeric', 'min:1', 'max:100'],
            'admin_commission' => ['required', 'numeric', 'min:1', 'max:100'],
            'partner_commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'requested_amount' => ['nullable', 'numeric'],
        ];
    }

    /**
     * A partner client's success fee is split admin / partner, all of it:
     * the two rates add up to 100%.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('partner_commission')) {
                    return;
                }

                $total = (float) $this->input('admin_commission') + (float) $this->input('partner_commission');
                if (abs($total - 100) > 0.001) {
                    $validator->errors()->add('partner_commission', __('application.commission_split_must_total_100'));
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'funding_application_id.required' => __('application.funding_application_id_required'),
            'funding_application_id.integer' => __('application.funding_application_id_integer'),
            'funding_application_id.exists' => __('application.funding_application_id_exists'),
            'total_commission.required' => __('application.total_commission_required'),
            'total_commission.numeric' => __('application.total_commission_numeric'),
            'total_commission.min' => __('application.total_commission_min'),
            'total_commission.max' => __('application.total_commission_max'),
            'admin_commission.required' => __('application.admin_commission_required'),
            'admin_commission.numeric' => __('application.admin_commission_numeric'),
            'admin_commission.min' => __('application.admin_commission_min'),
            'admin_commission.max' => __('application.admin_commission_max'),
            'partner_commission.numeric' => __('application.partner_commission_numeric'),
            'partner_commission.min' => __('application.partner_commission_min'),
            'partner_commission.max' => __('application.partner_commission_max'),
            'requested_amount.numeric' => __('application.requested_amount_numeric'),
            'requested_amount.min' => __('application.requested_amount_min'),
        ];
    }
}
