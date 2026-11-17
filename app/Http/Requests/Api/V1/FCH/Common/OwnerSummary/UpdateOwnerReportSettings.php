<?php

namespace App\Http\Requests\Api\V1\FCH\Common\OwnerSummary;

use App\Models\OwnerSummary\OwnerReportSetting;
use Illuminate\Validation\Validator;

class UpdateOwnerReportSettings extends FetchOwnerReportSettings
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'recipients' => ['present', 'array', 'max:' . OwnerReportSetting::MAX_RECIPIENTS],
            // "filter" as well as "rfc": rfc alone accepts name@domain with no dot.
            'recipients.*' => ['required', 'string', 'email:rfc,filter', 'distinct:ignore_case'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipients.max' => __('owner_summary.recipients_too_many', ['max' => OwnerReportSetting::MAX_RECIPIENTS]),
            'recipients.*.required' => __('owner_summary.recipient_invalid'),
            'recipients.*.string' => __('owner_summary.recipient_invalid'),
            'recipients.*.email' => __('owner_summary.recipient_invalid'),
            'recipients.*.distinct' => __('owner_summary.recipient_duplicate'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->boolean('enabled') && $this->input('recipients') === []) {
                    $validator->errors()->add('recipients', __('owner_summary.recipients_required'));
                }
            },
        ];
    }
}
