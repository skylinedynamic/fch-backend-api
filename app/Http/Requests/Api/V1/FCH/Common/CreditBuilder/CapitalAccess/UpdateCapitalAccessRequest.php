<?php

namespace App\Http\Requests\Api\V1\FCH\Common\CreditBuilder\CapitalAccess;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\CreditBuilder\CapitalAccess;

class UpdateCapitalAccessRequest extends BaseApiRequest
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
            'name' => 'sometimes|required|string|max:255',
            'sub_title' => 'required|string|max:255',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'target_exposure_min' => 'required|numeric|min:0',
            'target_exposure_max' => 'required|numeric|gte:target_exposure_min',
            'est_timeline_min' => 'required|integer|min:0',
            'est_timeline_max' => 'required|integer|gte:est_timeline_min',
            'what_you_will_get' => 'required|array|min:1',
            'what_you_will_get.*' => 'required|string|min:1',
            'pre_flight_checklist' => 'required|array|min:1',
            'pre_flight_checklist.*' => 'required|string|min:1',
            'status' => 'required|string|in:' . CapitalAccess::STATUS_ACTIVE . ',' . CapitalAccess::STATUS_INACTIVE,
            'minimum_fico_requirement' => 'nullable|integer|min:300|max:850',
            'maximum_utilization_threshold' => 'nullable|numeric|min:0|max:100',
            'difficulty_modifier' => 'nullable|numeric',
            'allow_recent_late_payment' => 'nullable|boolean',
            'late_payment_lookback_months' => 'nullable|integer|min:0',
            'credit_accounts' => 'required|array|min:1',
            'credit_accounts.*.id' => 'required|integer|exists:credit_accounts,id',
            'credit_accounts.*.why_this_card' => 'nullable|string',
            'credit_accounts.*.est_limit_min' => 'nullable|numeric',
            'credit_accounts.*.est_limit_max' => 'nullable|numeric',
            'credit_accounts.*.hard_pull' => 'nullable|in:Yes,No',
            'credit_accounts.*.what_you_need' => 'nullable|array',
            'credit_accounts.*.what_you_need.*' => 'nullable|string',
            'credit_accounts.*.access_time' => 'nullable|string',
            'credit_accounts.*.access_day' => 'nullable|integer|min:1|max:60',
            'credit_accounts.*.access_day_time' => 'nullable|string',
            'credit_accounts.*.minimum_fico_requirement' => 'nullable|integer|min:300|max:850',
            'credit_accounts.*.maximum_utilization_threshold' => 'nullable|numeric|min:0|max:100',
            'credit_accounts.*.difficulty_modifier' => 'nullable|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                 => __('credit_builder.validation_capital_access_name_required'),
            'name.max'                      => __('credit_builder.validation_capital_access_name_max'),
            'credit_accounts.required'      => __('credit_builder.validation_capital_access_credit_accounts_required'),
            'credit_accounts.array'         => __('credit_builder.validation_capital_access_credit_accounts_array'),
            'credit_accounts.min'           => __('credit_builder.validation_capital_access_credit_accounts_min'),
            'credit_accounts.*.id.exists'   => __('credit_builder.validation_capital_access_credit_accounts_id_exists'),
            'target_exposure_min.required'  => __('credit_builder.validation_capital_access_target_exposure_min_required'),
            'target_exposure_max.required'  => __('credit_builder.validation_capital_access_target_exposure_max_required'),
            'target_exposure_max.gte'       => __('credit_builder.validation_capital_access_target_exposure_max_gte'),
            'est_timeline_max.gte'          => __('credit_builder.validation_capital_access_est_timeline_max_gte'),
            'what_you_will_get.required'    => __('credit_builder.validation_capital_access_what_you_will_get_required'),
            'pre_flight_checklist.required' => __('credit_builder.validation_capital_access_pre_flight_checklist_required'),
        ];
    }
}
