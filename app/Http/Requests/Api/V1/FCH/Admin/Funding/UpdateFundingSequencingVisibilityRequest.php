<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateFundingSequencingVisibilityRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_fico'                       => ['required', 'integer', 'min:300', 'max:850'],
            'min_tib_months'                 => ['required', 'integer', 'min:0', 'digits_between:1,5'],
            'min_monthly_revenue'            => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'min_monthly_deposits'           => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'min_average_daily_balance'      => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'max_nsf_count'                  => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'max_negative_days'              => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'min_dscr'                       => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'allowed_states'                 => 'nullable|array',
            'allowed_states.*'               => 'string',
            'restricted_states'              => 'nullable|array',
            'restricted_states.*'            => 'string',
            'allowed_industries'             => 'nullable|array',
            'allowed_industries.*'           => 'string',
            'restricted_industries'          => 'nullable|array',
            'restricted_industries.*'        => 'string',
            'require_completed_application'  => 'nullable|boolean',
            'require_documents_uploaded'     => 'nullable|boolean',
            'manual_review_allowed'          => 'nullable|boolean',
            'exception_allowed'              => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'min_monthly_revenue.regex'      => 'Minimum monthly revenue is out of range or has invalid decimal format.',
            'min_monthly_deposits.regex'     => 'Minimum monthly deposits is out of range or has invalid decimal format.',
            'min_average_daily_balance.regex'=> 'Minimum average daily balance is out of range or has invalid decimal format.',
            'min_dscr.regex'                 => 'Minimum DSCR is out of range or has invalid decimal format.',
        ];
    }
}
